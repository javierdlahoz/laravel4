<?php
namespace Charity\Howto\Services;

use App;
use Auth;
use Carbon\Carbon;
use Charity\Howto\Debug\AppDebugger;
use Charity\Howto\Exceptions\ChtException;
use Charity\Howto\Exceptions\Exception;
use Charity\Howto\Exceptions\ValidationException;
use Charity\Howto\Repositories\UserRepository;
use Charity\Howto\Repositories\UserVariationRepository;
use Charity\Howto\Traits\FileUploadable;
use Charity\Howto\Traits\InfusionsoftTaggable;
use Charity\Howto\Validation\UserValidator;
use Config;
use Guzzle\Http\Client;
use Hash;
use Mail;
use Order;
use Product;
use Session;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use User;
use UserVariation;
use Variation;

class UserService {

    use InfusionsoftTaggable, FileUploadable;

    protected $remoteUploadDirectory = '/users/images/'; // Base upload path, used by FileUploadable trait

    /**
     * @var \Charity\Howto\Repositories\UserRepository
     */
    private $userRepository;
    /**
     * @var \Charity\Howto\Validation\UserValidator
     */
    private $userValidator;

    public $repository;
    /**
     * @var UserVariationRepository
     */
    private $userVariationRepository;
    /**
     * @var AppDebugger
     */
    private $debugger;

    public function __construct(UserRepository $userRepository, UserValidator $userValidator, UserVariationRepository $userVariationRepository, AppDebugger $debugger){
        $this->userRepository = $userRepository;
        $this->userValidator = $userValidator;
        $this->repository = $userRepository;
        $this->userVariationRepository = $userVariationRepository;
        $this->debugger = $debugger;
    }

    public function userForgotPassword($userInput)
    {
        try {
            $this->userValidator->validateForCustom('forgotPassword', $userInput);
        } catch (ValidationException $e) {
            throw new Exception($e->getErrorsAsHtml());
        }

        if (!$user = $this->userRepository->findByEmail($userInput['email'])) {
            throw new Exception('No account matching that email exists');
        }

    }

    public function sendContactRequest($userInput)
    {
        // Validate Google reCAPTCHA
        $options = [
            'query' => [
                'secret' => '6Le04gATAAAAALpKh1K2ozTY4EB0sUld6hEE5rVo',
                'response' => empty($userInput['g-recaptcha-response']) ? null : $userInput['g-recaptcha-response'],
                'remoteip' => \Request::getClientIp()
            ]
        ];
        $response = (new Client())->get('https://www.google.com/recaptcha/api/siteverify', null, $options)->send()->json();
        if (empty($response['success']) || $response['success'] !== true) {
            throw new ChtException('Invalid CAPTCHA response - please verify that you are not a robot.  Please contact us via phone if the issue persists.');
        }

        try {
            $this->userValidator->validateForCustom('contactSubmit', $userInput);
        } catch (ValidationException $e) {
            throw new Exception($e->getErrorsAsHtml());
        }

        $expertRequest = !empty($userInput['expert_request']); // Determine if this is a 'Become CHT Expert' request or not, which changes the subject
        Mail::send('emails.contact-us', ['request' => $userInput], function($message) use ($expertRequest, $userInput) {

            $message->subject('Charity How To: ' . ($expertRequest ? 'Teach At CharityHowTo' : 'Contact Us') . ' Request')
              ->from($userInput['email'], $userInput['first_name'] . ' ' . $userInput['last_name'])
              ->to(['support@charityhowto.com', 'kurt@charityhowto.com']);
        });

        return true;
    }

    public function setInfusionsoftId($user, $infusionsoftId)
    {
        return $this->userRepository->updateInfusionsoftId($user, $infusionsoftId);
    }

    /**
     * Triggered when receiving a thank-you request from Infusionsoft.  Needs to be refactored a bit...
     * @param $ifsContactId
     * @param $ifsOrderId
     * @return bool
     * @throws Exception
     */
    public function userPurchaseAction($ifsContactId, $ifsOrderId, $gALeadSource = '')
    {
        $membershipLevel = null;

        /** @var InfusionsoftService $infusionsoftService */
        $infusionsoftService = App::make('Charity\Howto\Services\InfusionsoftService');
        $sessionService = App::make('Charity\Howto\Services\SessionService');

        try {
            $ifsContact = $infusionsoftService->infusionsoft->Contact->getClass($ifsContactId);
            $ifsOrder = $infusionsoftService->infusionsoft->Job->getClass($ifsOrderId);
            $ifsInvoice = $infusionsoftService->infusionsoft->Invoice->findByJobId($ifsOrder->Id);
            $currentUser = $sessionService->getActiveUser();
        } catch (\Infusionsoft_Exception $e) {
            $this->debugger->sendCriticalNotification(
                'User Purchase Action',
                array_only(get_defined_vars(),
                ['ifsContactId', 'ifsOrderId', 'ifsContact', 'ifsOrder', 'ifsInvoice']),
                $e);

            throw new Exception("Problem when loading order information.  Please contact support with the following information: Contact ID #$ifsContactId, Order ID #$ifsOrderId.  Sorry for the inconvenience!");
        }

        if (!$ifsContact || !$ifsOrder || !$ifsInvoice || $ifsOrder->ContactId != $ifsContact->Id) {
            $this->debugger->sendCriticalNotification(
                'User Purchase Action: Step 2',
                array_only(get_defined_vars(),
                ['ifsContactId', 'ifsOrderId', 'ifsContact', 'ifsOrder', 'ifsInvoice']
            ));

            throw new Exception('Unable to find CRM order');
        }
        if(!empty($currentUser->infusionsoft_contact_id)){
          if($currentUser->infusionsoft_contact_id != $ifsContact->Id && $currentUser->infusionsoft_contact_id!=null ){
            $ifsContact->Email = $currentUser->email;
            $ifsContact->FirstName = $currentUser->first_name;
            $ifsContact->LastName = $currentUser->last_name;
            $ifsContact->Id = $currentUser->infusionsoft_contact_id;
            $ifsContact->_LeadSourceGoogleAnalytics = $currentUser->google_analytics_lead_source;
            $ifsContact->Leadsource = $currentUser->infusionsoft_lead_source;

          }
        }

        //Set lead source from cookie to infusionsoft
        $today = Carbon::today();
        $tomorrow = Carbon::tomorrow();
        $LeadSource = \Config::get('services.infusionsoft.app_name').'.infusionsoft.com/app/manageCart/showManageOrder';
        if(empty($ifsContact->_LeadSourceGoogleAnalytics) && Carbon::parse($ifsContact->DateCreated)->between($today,$tomorrow)){

            $ifsContact->_LeadSourceGoogleAnalytics = $gALeadSource;
            $ifsContact->Leadsource = $LeadSource;
            //review leadsource capture page

            if(!empty($gALeadSource)){
                $infusionsoftService->infusionsoft->Contact->addOrUpdate([
                    'Id' => $ifsContact->Id,
                    'Email' => $ifsContact->Email,
                    '_LeadSourceGoogleAnalytics' => $ifsContact->_LeadSourceGoogleAnalytics,
                    'Leadsource' => $ifsContact->Leadsource,
                ]);
            }
        }

        $infusionsoftService->infusionsoft->Contact->safeOptIn($ifsContact->Email, 'Completed CharityHowTo Order');

//        if ($ifsInvoice->PayStatus != 1) {
//            throw new Exception('Order is not paid for!');
//        }

        $orderItems = $infusionsoftService->infusionsoft->OrderService->getOrderItems($ifsOrder->Id);
        if (empty($orderItems)) {
            $this->debugger->sendCriticalNotification(
                'User Purchase Action: No Order Items',
                array_only(get_defined_vars(),
                ['ifsContactId', 'ifsOrderId', 'ifsContact', 'ifsOrder', 'ifsInvoice', 'orderItems']
            ));

            throw new Exception('Order does not have any items');
        }

        foreach ($orderItems as $orderItem) {
            if (!$orderItem->ProductId) continue; // If it is not a product we are not interested
            if (!empty($orderItem->SubscriptionPlanId)) {
                $membershipLevel = $this->resolveOrderItemMembershipLevel($orderItem);
                break;
            }
        }

        $creationType = 'order-billing-user-no-email';//Update: Creation of a new user without notification email
        if ($membershipLevel) {
            $creationType = 'order-billing-membership';
        }

        // Create a base user account for the purchaser
        $user = $this->findOrCreateByEmail($ifsContact->Email, [
            'first_name' => $ifsContact->FirstName,
            'last_name' => $ifsContact->LastName,
            'infusionsoft_contact_id' => $ifsContact->Id,
            'membershipLevel' => $membershipLevel,
            'infusionsoft_lead_source' => $ifsContact->Leadsource,
            'google_analytics_lead_source' => $ifsContact->_LeadSourceGoogleAnalytics,
        ], $creationType);

        Auth::login($user); // Log the user into their new account, this is technically a security hole, TODO: If the account exists, give them access, but force them to enter their password to continue

        /** @var OrderService $orderService */
        $orderService = App::make('Charity\Howto\Services\OrderService');
        $productRepository = App::make('Charity\Howto\Repositories\ProductRepository');
        $variationService = App::make('Charity\Howto\Services\VariationService');

        // Create a new order
        $order = $orderService->createForUser($user, $ifsOrder, $ifsInvoice);

        // Now loop through all of the items on the order.
        foreach ($orderItems as $orderItem) {
            if (!$orderItem->ProductId) continue; // If it is not a product we are not interested

            if (!empty($orderItem->SubscriptionPlanId) && $membershipLevel) {
                $this->completeMembershipProcess($user, $membershipLevel, $orderItem, $ifsContactId);
                $orderService->addOrderMembership($order, $orderItem, $membershipLevel->id);
                continue;
            }
            if (!$product = $productRepository->findByInfusionsoftId($orderItem->ProductId)) continue; // If the product does not match one of ours, we cant do anything
            $variation = $variationService->findByCrmDescription($product->id, $orderItem->ItemDescription); // Use the description of the order item to attempt to find a matching variation

            $order = $orderService->addOrderItem($order, $orderItem, $product->id, $variation ? $variation->id : null); // Add the item to our order
            if ($product->type == Product::$_TYPE_LIVE_WEBINAR) {
                // Flag the order for attendee information, and do not give product access to the billing user
                $order = $orderService->setNeedsAttendees($order);
            } else {
                $user = $this->completedPurchaseAction($user, $product, $variation ? : null);
            }
        }

        if (!$order->hasLiveWebinars()) {
            $order = $orderService->repository->updateStatus($order, Order::$_STATUS_COMPLETED);
        }

        if ($order->hasLibraryItems() && !$order->hasGoogleGrants()){
            //if there is not live webinar or google grants product send account credentials for the new user
            if($user->status == User::$_STATUS_AWAITING_VERIFICATION && !$order->hasGoogleGrants()){
                $this->sendEmailAccountWithNewPassword($user);
            }
        }

        return (object)['order'=>$order, 'membershipLevel'=>$membershipLevel];
    }

    public function activateUser($userId, $activationCode, $userInput, $loginUser = false)
    {
        if (!$user = $this->userRepository->findForActivation($userId, $activationCode)) {
            throw new Exception('Unable to find user to activate');
        }

        try {
            $this->userValidator->validateForCustom('userActivate', $userInput);
        } catch (ValidationException $e) {
            throw new Exception($e->getErrorsAsHtml());
        }

        $userData = [
            'status' => User::$_STATUS_ACTIVE,
            'password' => $userInput['password'],
            'activation_token' => null,
            'first_name' => isset($userInput['first_name'])? $userInput['first_name']:'',
            'last_name' => isset($userInput['last_name'])? $userInput['last_name']:'',
        ];

        $user = $this->userRepository->update($user, $userData);

        if ($loginUser) {
            \Auth::login($user);
        }

        return $user;
    }

    /**
     * @deprecated
     * @param $user
     * @param $productId
     * @return \Illuminate\Support\Collection|null|\User|static
     * @throws \Charity\Howto\Exceptions\Exception
     */
    public function toggleProductAccess($user, $productId)
    {
        $user = $user instanceof User ? $user : $this->userRepository->find($user);
        if (!$user) {
            throw new Exception('Unable to find user');
        }
        if (!$product = $user->products->find($productId)) {
            throw new Exception('User does not have access to this product');
        }

        $user->products()->updateExistingPivot($product->id, [
            'active' => $product->pivot->active ? false : true,
        ]);

        return $user;
    }

    public function toggleVariationAccess($user, $variationId)
    {
        $user = $user instanceof User ? $user : $this->userRepository->find($user);
        if (!$user) {
            throw new Exception('Unable to find user');
        }
        if (!$userVariation = $user->userVariations->keyBy('variation_id')->get($variationId)) {
            throw new Exception('User does not have access to this variation');
        }

        $userVariation->status = $userVariation->isActive() ? UserVariation::$_STATUS_INACTIVE : UserVariation::$_STATUS_ACTIVE;
        $userVariation->save();

        return $user;
    }

    public function setProductAccess($user, $productId, $active = true)
    {
        $user = $user instanceof User ? $user : $this->userRepository->find($user);
        if (!$user) {
            throw new Exception('Unable to find user');
        }
        if (!$product = $user->products->find($productId)) {
            throw new Exception('User ' . $user->id . ' does not have access to this product.');
        }

        if ($active) {
            if (!$product->pivot->active) {
                $user->products()->updateExistingPivot($product->id, [
                    'active' => true
                ]);
            }
        } else {
            if ($product->pivot->active) {
                $user->products()->updateExistingPivot($product->id, [
                    'active' => false
                ]);
            }
        }

        return $user;
    }

    public function setVariationAccess($user, $variationId, $status)
    {
        $user = $user instanceof User ? $user : $this->userRepository->find($user);
        if (!$user) {
            throw new Exception('Unable to find user');
        }
        if (!$userVariation = $user->userVariations->keyBy('variation_id')->get($variationId)) {
            throw new Exception('User does not have access to this variation');
        }

        if ($userVariation->status != $status) {
            $userVariation->status = $status;
            $userVariation->save();
        }

        return $user;
    }

    public function removeProductAccess($user, $productId)
    {
        $user = $user instanceof User ? $user : $this->userRepository->find($user);
        if (!$user) {
            throw new Exception('Unable to find user');
        }
        if (!$product = $user->products->find($productId)) {
            throw new Exception('User does not have access to this product');
        }

        $user->products()->detach([$product->id]);

        /** If the product has variation(s) delete variations*/
        $variations = $product->variationList();
        if(!empty($variations)){
            foreach($variations as $variationId => $variation){
                if($userVariation = $user->userVariations->keyBy('variation_id')->get($variationId)){
                    $userVariation->delete();
                }
            }
        }

        return $user;
    }

    public function removeVariationAccess($user, $variationId)
    {
        $user = $user instanceof User ? $user : $this->userRepository->find($user);
        if (!$user) {
            throw new Exception('Unable to find user');
        }
        if (!$userVariation = $user->userVariations->keyBy('variation_id')->get($variationId)) {
            throw new Exception('User does not have access to this presentation');
        }

        /** If user has a product from the variation remove access to product*/
        $variation = \Variation::find($userVariation->variation_id);
        if ($userProduct = $user->products->find($variation->product->id)) {
            $user->products()->detach([$userProduct->id]);
        }

        $userVariation->delete();

        return $user;
    }

    /**
     * Grant this user access to a product/variation and intelligently sets the status according to the type
     * @param $user
     * @param $product
     * @param null $variation
     * @return User
     */
    public function grantProductAccessWithStatus($user, $product, $variation = null)
    {
        if ($variation) {
            // If they are getting access to a variation, we will only make it active if it is the day of or before the variation
            $currentTime = Carbon::now(Config::get('app.timezone'))->format('Y-m-d'); // Strip any time information
            $variationTime = Carbon::parse($variation->date, Config::get('app.timezone'))->format('Y-m-d');
            $active = Carbon::parse($variationTime)->lte(Carbon::parse($currentTime)); // If we are on the day of or any time before, give access

            if (!$user->userVariations->keyBy('variation_id')->get($variation->id)) {
                // Grant access to the variation
                $user->userVariations->push($this->userVariationRepository->create([
                    'user_id' => $user->id,
                    'variation_id' => $variation->id,
                    'status' => $active ? UserVariation::$_STATUS_ACTIVE : UserVariation::$_STATUS_INACTIVE
                ]));


            }

            // Give them access to the parent product using the same logic
            if (!$user->products->contains($product->id)) {
                $user->products()->attach($product->id, ['active' => $active ? true : false, 'source' => 'Purchase']);
            } else {
               /**
                * they may have become a member, added something to their library
                * then cancelled their membership. we want to keep the product there in case they renew
                * but if they decide to purchase it directly, we need to handle it showing back up correctly
                */
                $user->products()->updateExistingPivot($product->id,  ['active' => $active ? true : false, 'source' => 'Purchase']);
            }

        } else {
            // No variation provided, they just want to give access to a product - no rules here.
            if (!$user->products->contains($product->id)) {
                $user->products()->attach($product->id, ['active' => true, 'source' => 'Purchase']);
            } else {
                $user->products()->updateExistingPivot($product->id,  ['active' => true , 'source' => 'Purchase']);
            }
        }

        return $user;
    }

    /**
     * Apply tags to contact, grant access to products/variations, and sign up for any webinars
     * @param $user
     * @param $product
     * @param null $variation
     * @param null $webform
     * @param null $orderItem
     * @return User
     */
    public function completedPurchaseAction($user, $product, $variation = null, $webform = null, $orderItem = null)
    {
        $webinarService = App::make('Charity\Howto\Services\CitrixApiService');

        $this->applyProductTags($user->infusionsoft_contact_id, $product, $variation ? : null);
        $user = $this->grantProductAccessWithStatus($user, $product, $variation ? : null);
        list($registrantId, $joinUrl) = $webinarService->addWebinarRegistrantByPurchase($user->toArray(), $webform, $product, $variation);

        var_dump($registrantId, $joinUrl);
        die();

        // Now lets find the userVariation and update it with webinar/order information
        if ($variation && $userVariation = $user->userVariations->keyBy('variation_id')->get($variation->id)) {
            $this->userVariationRepository->update($userVariation, [
                'webinar_api_registrant_id' => $registrantId,
                'webinar_api_join_url' => $joinUrl,
                'order_item_id' => $orderItem ? $orderItem->id : null,
            ]);
        }

        return $user;
    }

    public function completeMembershipProcess($user, $membershipLevel, $orderItem, $ifsContactId)
    {
        $membershipLevelService = App::make('Charity\Howto\Services\MembershipLevelService');

        $membershipLevelService->addMembershipLevelByPurchase($user, $membershipLevel, $orderItem->OrderId);

        $this->applyTagsToContact($ifsContactId, $membershipLevel->infusionsoft_tags);
    }

    public function resolveOrderItemMembershipLevel($orderItem)
    {
        $membershipLevelRepository = App::make('Charity\Howto\Repositories\MembershipLevelRepository');

        return $membershipLevelRepository->findByInfusionsoftId($orderItem->ProductId);
    }

    public function simulateProductPurchase($userId, $productId, $variationId = null)
    {
        if (!$user = $this->userRepository->find($userId)) {
            throw new Exception('Unable to find requested user');
        }
        $productRepository = App::make('Charity\Howto\Repositories\ProductRepository');
        if (!$product = $productRepository->find($productId)) {
            throw new Exception('Unable to find requested product');
        }
        $user = $this->completedPurchaseAction($user, $product, $variationId ? $product->variations->find($variationId) : null);

        return $user;
    }

    public function applyProductTags($ifsContactId, $product, $variation = null)
    {
        if (empty($ifsContactId)) return true;
        $this->applyTagsToContact($ifsContactId, $product->infusionsoft_tags);
        if ($variation) {
            $this->applyTagsToContact($ifsContactId, $variation->infusionsoft_tags);
        }
        if ($product->author) {
            $this->applyTagsToContact($ifsContactId, $product->author->infusionsoft_tags);
        }
        if (count($product->categories)) {
            foreach ($product->categories as $category) {
                $this->applyTagsToContact($ifsContactId, $category->infusionsoft_tags);
            }
        }

        return true;
    }

    public function generateActivationToken()
    {
        return str_random(32);
    }

    public function generateTempPassword()
    {
        return str_random(6);
    }

    public function sendAccountPasswordEmail($user, $newPassword)
    {
        Mail::send('emails.auth.temporary-password', ['user' => $user, 'newPassword' => $newPassword], function($message) use ($user) {
            $message->to($user->email)->subject('Welcome to CharityHowTo - ** Important Account Login Information**');
        });
    }

    public function sendAccountPasswordMembershipEmail($user, $newPassword, $membershipLevel)
    {
        Mail::send('emails.auth.new-membership', ['user' => $user, 'newPassword' => $newPassword, 'membershipLevel' => $membershipLevel], function($message) use ($user) {
            $message->to($user->email)->subject('Welcome to CharityHowTo - ** Important Account Login Information**');
        });
    }

    public function sendActivationEmail($user, $tempPassword)
    {
        Mail::send('emails.auth.activation', ['user' => $user, 'tempPassword' => $tempPassword], function($message) use ($user) {
            $message->to($user->email)->subject('One Last Step: Activate Your Charity How To Account Now');
        });

        return true;
    }

    public function sendActivationEmailAdditionalMember($user, $tempPassword, $masterMembership, $masterUser)
    {
        Mail::send('emails.auth.new-additional-member', [
          'user' => $user,
          'tempPassword' => $tempPassword ,
          'masterMembership' => $masterMembership[0],
          'masterUser' => $masterUser
        ],
           function($message) use ($user, $masterMembership) {
               $message
                   ->to($user->email)
                   ->subject($masterMembership[0]->additional_user_email_subject);
           });

        return true;
    }

    public function userUpdateSettings($userInput)
    {
        $user = App::make('Charity\Howto\Services\SessionService')->getActiveUser();

        $userInput['email'] = isset($userInput['email']) ? $userInput['email'] : $user->email;
        $userInput['first_name'] = isset($userInput['first_name']) ? $userInput['first_name'] : $user->first_name;
        $userInput['last_name'] = isset($userInput['last_name']) ? $userInput['last_name'] : $user->last_name;

        try
        {
            $this->userValidator->userSettings($userInput, $user);
        } catch (ValidationException $e)
        {
            throw new Exception($e->getErrorsAsHtml());
        }

        $updatingEmail = $userInput['email'] != $user->email;

        /** @var InfusionsoftService $infusionsoftService */
        $infusionsoftService = App::make('Charity\Howto\Services\InfusionsoftService');

        $ifsContact = $infusionsoftService->infusionsoft->Contact->addOrUpdate([
            'infusionsoft_contact_id' => $user->infusionsoft_contact_id,
            'email' => $updatingEmail ? $user->email : $userInput['email'], // Load the old contact if they are updating
            'first_name' => $userInput['first_name'],
            'last_name' => $userInput['last_name']
        ]);

        // Then reset the email on the contact if they are updating
        if ($updatingEmail)
        {
            $ifsContact->Email = $userInput['email'];
            $ifsContact->save();
            $infusionsoftService->infusionsoft->Contact->safeOptIn($userInput['email'], 'Updated email in CharityHowTo Account Settings');
        }

        if (isset($userInput['picture']) && strpos($userInput['picture'],'base64') !== false)
        {
            $data = $userInput['picture'];

            list($type, $data) = explode(';', $data);
            list(, $data) = explode(',', $data);
            $data = base64_decode($data);
            $path = public_path().'/uploads/files';
            file_put_contents($path . '/' . $user->id . '.png', $data);
            chmod($path . '/' . $user->id . '.png', 0777);
            $userInput['profile_image'] = $this->uploadFromLocalFileToS3( new UploadedFile($path . '/' . $user->id . '.png', $user->id ));
        }

        $userData = [
            'email' => $userInput['email'],
            'first_name' => $userInput['first_name'],
            'last_name' => $userInput['last_name'],
            'alternative_email' => isset($userInput['alternative_email']) ? $userInput['alternative_email'] : $user->alternative_email,
            'mobile' => isset($userInput['mobile']) ? preg_replace("/[^0-9]/", "",$userInput['mobile']) : $user->mobile,
            'infusionsoft_contact_id' => $ifsContact->Id,
            'profile_image' => isset($userInput['profile_image']) ? $userInput['profile_image'] : $user->profile_image,
        ];

        if (! empty($userInput['password']))
        {
            $userData['password'] = $userInput['password'];
        }

        $user = $this->userRepository->update($user, $userData);

        return $user;
    }

    /**
     * Create a new user by email address.  Uses creation type to determine if the user is notified.
     * @param $emailAddress
     * @param array $additionalInfo
     * @param string $creationType
     * @return UserService
     */
    public function createByEmail($emailAddress, $additionalInfo = [], $creationType = 'webform', $furtherInfo = [])
    {
        $userData = [
            'email' => $emailAddress,
            'user_role' => User::$_ROLE_USER,
            'first_name' => empty($additionalInfo['first_name']) ? null : $additionalInfo['first_name'],
            'last_name' => empty($additionalInfo['last_name']) ? null : $additionalInfo['last_name'],
            'infusionsoft_contact_id' => empty($additionalInfo['infusionsoft_contact_id']) ? null : $additionalInfo['infusionsoft_contact_id'],
            'password' => empty($additionalInfo['password']) ? $this->generateTempPassword() : $additionalInfo['password'],
            'infusionsoft_lead_source' => empty($additionalInfo['infusionsoft_lead_source']) ? null : $additionalInfo['infusionsoft_lead_source'],
            'google_analytics_lead_source' => empty($additionalInfo['google_analytics_lead_source']) ? null : $additionalInfo['google_analytics_lead_source'],
        ];

        // Depending on the way the user is created, we notify them or not
        switch ($creationType) {
            default:
            case 'basic-registration':
            case 'webform':
            case 'webform-live-webinar-attendees':
            case 'webform-recorded-webinar':
                $user = $this->createUserNeedingVerification($userData);
                break;
            case 'webform-live-webinar':
            case 'live-webinar-attendee':
                $user = $this->createUserForLiveWebinar($userData);
                break;
            case 'order-billing-user':
                $user = $this->createUserWithPassword($userData);
                break;
            case 'order-billing-membership':
                $user = $this->createUserWithPasswordForMembership($userData, $additionalInfo['membershipLevel']);
                break;
            case 'order-billing-user-no-email':
                $user = $this->createUserWithPasswordWithoutEmail($userData);
                break;
            case 'additional-member':
                $user = $this->createUserForAdditionalMember($userData, $furtherInfo);
                break;
        }

        return $user;
    }

    /**
     * Undocumented function
     *
     * @param User $user
     * @return User
     */
    public function resetPasswordAndSendEmail($user)
    {
        return $this->sendEmailAccountWithNewPassword($user, false);
    }

    /**
     * Reset Password and send account email to previous created user
     * @param $user
     * @return User
     */
    private function sendEmailAccountWithNewPassword($user, $isChanginStatus = true)
    {
        if ($isChanginStatus) {
            $user->status = User::$_STATUS_ACTIVE;
        }

        $newPassword = $this->generateTempPassword();
        $user->password = $newPassword;
        $user->save();
        $this->sendAccountPasswordEmail($user, $newPassword);

        return $user;
    }

    /**
     * Create a new user with no notification, a billing user.
     * @param $userInfo
     * @return User
     */
    private function createUserWithPasswordWithoutEmail($userInfo)
    {
        $baseUserInfo = [
            'status' => User::$_STATUS_AWAITING_VERIFICATION,
            'activation_token' => $this->generateActivationToken(),
        ];

        $user = $this->userRepository->create($baseUserInfo + $userInfo);

        return $user;
    }

    /**
     * Create a user that needs to be verified and send them an email
     * @param $userInfo
     * @return User
     */
    private function createUserNeedingVerification($userInfo)
    {
        $baseUserInfo = [
            'status' => User::$_STATUS_UNVERIFIED,
            'activation_token' => $this->generateActivationToken(),
        ];

        $user = $this->userRepository->create($baseUserInfo + $userInfo);
        $this->sendActivationEmail($user, $userInfo['password']);

        return $user;
    }

    /**
     * Create a new user for live webinars. They are not notified.
     * @param $userInfo
     * @return User
     */
    private function createUserForLiveWebinar($userInfo)
    {
        $baseUserInfo = [
            'status' => User::$_STATUS_AWAITING_VERIFICATION,
            'activation_token' => $this->generateActivationToken(),
        ];

        $user = $this->userRepository->create($baseUserInfo + $userInfo);

        return $user;
    }

    /**
     * Create a new user with a new password. They will be notified!
     * @param $userInfo
     * @return User
     */
    private function createUserWithPassword($userInfo)
    {
        $baseUserInfo = [
            'status' => User::$_STATUS_ACTIVE,
        ];

        $user = $this->userRepository->create($baseUserInfo + $userInfo);
        $this->sendAccountPasswordEmail($user, $userInfo['password']);

        return $user;
    }

    private function createUserWithPasswordForMembership($userInfo, $membershipLevel)
    {
        $baseUserInfo = [
            'status' => User::$_STATUS_ACTIVE,
        ];

        $user = $this->userRepository->create($baseUserInfo + $userInfo);
        $this->sendAccountPasswordMembershipEmail($user, $userInfo['password'], $membershipLevel);

        return $user;
    }
    /**
     * Create a user  attached to membership plan with email
     * @param $userInfo
     * @return User
     */
    private function createUserForAdditionalMember($userInfo, $masterMembership)
    {
        $baseUserInfo = [
            'status' => User::$_STATUS_UNVERIFIED,
            'activation_token' => $this->generateActivationToken(),
        ];

        $user = $this->userRepository->create($baseUserInfo + $userInfo);
        $masterUser = \User::find($masterMembership[0]->user_id);
        $this->sendActivationEmailAdditionalMember($user, $userInfo['password'], $masterMembership, $masterUser);

        return $user;
    }

    /**
     * Find an existing user by email and update their information, or create a new user (This notifies users based on the creation type)
     * @param $emailAddress
     * @param array $additionalInfo
     * @param string $creationType
     * @return User
     */
    public function findOrCreateByEmail($emailAddress, $additionalInfo = [], $creationType, $furtherInfo = [])
    {
        if ($existingUser = $this->userRepository->findByEmail($emailAddress)) {
            // Update certain user information if present.
            if (!empty($additionalInfo)) {
                unset($additionalInfo['membershipLevel']);
                $existingUser = $this->repository->update($existingUser, $additionalInfo);
            }

            return $existingUser;
        }

        $newUser = $this->createByEmail($emailAddress, $additionalInfo, $creationType, $furtherInfo);

        return $newUser;
    }

    /**
     * User registering for the application
     * @param $userInput
     * @return static
     * @throws Exception
     */
    public function userCreate($userInput)
    {
        try {
            $this->userValidator->validateForCreation($userInput);
        } catch (ValidationException $e) {
            throw new Exception($e->getErrorsAsHtml());
        }

        /** @var InfusionsoftService $infusionsoftService */
        $infusionsoftService = App::make('Charity\Howto\Services\InfusionsoftService');

        $ifsContact = $infusionsoftService->infusionsoft->Contact->addOrUpdate($userInput);
        $infusionsoftService->infusionsoft->Contact->safeOptIn($ifsContact->Email, 'Registered for user account on CharityHowTo.com');

        $user = $this->findOrCreateByEmail($ifsContact->Email, [
            'first_name' => $ifsContact->FirstName,
            'last_name' => $ifsContact->LastName,
            'infusionsoft_contact_id' => $ifsContact->Id,
            'infusionsoft_lead_source' => $ifsContact->Leadsource,
            'google_analytics_lead_source' => $ifsContact->_LeadSourceGoogleAnalytics,
        ], 'basic-registration');

//        App::make('Charity\Howto\Services\SessionService')->loginByUser($user); // Automatically log the user into their new account

        return $user;
    }

    /**
     * Admin manually creating a user
     * @param $userInput
     * @return static
     * @throws Exception
     */
    public function adminCreate ($userInput)
    {
        try {
            $this->userValidator->validateForCustom('adminCreate', $userInput);
        } catch (ValidationException $e) {
            throw new Exception('Validation Errors: '.$e->getErrorsAsHtml());
        }

        $infusionsoftService = App::make('Charity\Howto\Services\InfusionsoftService');
        $ifsContact = $infusionsoftService->infusionsoft->Contact->addOrUpdate($userInput);
        $infusionsoftService->infusionsoft->Contact->safeOptIn($ifsContact->Email, 'Admin created user account on CharityHowTo.com');
        $userInput['infusionsoft_contact_id'] = $ifsContact->Id;

        $notify = (!empty($userInput['send_notification']))? true:false;
        unset($userInput['send_notification']);

        if (!$newUser = $this->userRepository->create($userInput)) {
            throw new Exception('Problem when creating new user');
        }

        if ($notify) {
            $this->sendAccountPasswordEmail($newUser, $userInput['password']);
        }

        return $newUser;
    }

    /**
     * Admin editing a user
     * @param $userId
     * @param $userInput
     * @return \Illuminate\Support\Collection|static
     * @throws Exception
     */
    public function adminEdit($userId, $userInput)
    {
        if (!$user = $this->userRepository->find($userId)) {
            throw new Exception('Unable to find requested user to update');
        }

        $notify = (!empty($userInput['send_notification']))? true:false;
        unset($userInput['send_notification']);

        if ($notify && empty($userInput['password']))
            throw new Exception('To send an "Account Details Email" you should enter a New Password.');

        try {
            $this->userValidator->validateUnique($userInput, $this->userValidator->adminUpdateRules, $user->id);
        } catch (ValidationException $e) {
            throw new Exception('Validation Errors: '.$e->getErrorsAsHtml());
        }

        $infusionsoftService = App::make('Charity\Howto\Services\InfusionsoftService');
        $ifsContact = $infusionsoftService->infusionsoft->Contact->addOrUpdate($userInput);
        $infusionsoftService->infusionsoft->Contact->safeOptIn($ifsContact->Email, 'Admin created user account on CharityHowTo.com');
        $userInput['infusionsoft_contact_id'] = $ifsContact->Id;

        if (empty($userInput['password'])) unset($userInput['password']); // If they do not want to update the password, remove it

        if (!$user = $this->userRepository->update($user, $userInput)) {
            throw new Exception('Problem when updating user');
        }

        if ($notify && !empty($userInput['password']))
            $this->sendAccountPasswordEmail($user, $userInput['password']);

        return $user;
    }

    /**
     * Admin deletion of a user
     * @param $userId
     * @return \Illuminate\Support\Collection|static
     * @throws Exception
     */
    public function adminDelete($userId)
    {
        if (!$user = $this->userRepository->find($userId)) {
            throw new Exception('Unable to find requested user to delete');
        }

        $user->products()->sync([]);
        foreach ($user->userVariations as $userVariation) {
            $userVariation->delete();
        }

        $orderService = App::make('Charity\Howto\Services\OrderService');
        foreach ($user->orders as $order) {
            $order = $orderService->adminDelete($order->id);
        }

        $user = $this->userRepository->delete($user);

        return $user;
    }

    /**
     * Admin adds tags for users, return an array with results.
     * @param $userInput
     * @return \Illuminate\Support\Collection|static
     * @throws Exception
     */

    public function adminTagStore($userInput)
    {
        $tags = $this->arrayToString(_e($userInput['infusionsoft_tags'], []));
        $userids = $userInput['userids'];
        $success = [];
        foreach ($userids as $userid) {
            try {
                $this->applyTagsToContact($userid, $tags);
                $success[$userid] = true;
            } catch (\Exception $e) {
                $success[$userid] = false;
            }
        }
        return $success;
    }

    public function trasnferMembership($source, $destination){
        try {
            $transfer = \DB::table('membership_level_user')
                ->where('user_id', $source->id)
                ->where('active', 1)
                ->update(['user_id' => $destination->id])
            ;
            return true;
         } catch (Exception $e) {
            return false;
         }
    }

    public function setRegistrantInfoToUserByEmail($email, $registrantInfo, $orderItem)
    {
        $user = $this->userRepository->findByEmail($email);
        $variation = $this->userVariationRepository->model->where('webinar_api_join_url', $registrantInfo['joinUrl'])->first();
        if (!$variation) {
            $variation = new UserVariation();
            $variation->user_id = $user->id;
            $variation->variation_id = $orderItem->variation->id;
            $variation->order_item_id = $orderItem->id;
            $variation->webinar_api_join_url = $registrantInfo['joinUrl'];
            $variation->webinar_api_registrant_id = $registrantInfo['registrantKey'];
            $variation->save();
        }
    }

}
