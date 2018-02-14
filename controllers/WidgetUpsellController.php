<?php
namespace Client;
use Charity\Howto\Repositories\ApplicationSettingRepository;
use Charity\Howto\Repositories\UserRepository;
use Charity\Howto\Repositories\ProductRepository;
use Charity\Howto\Services\SessionService;
use Charity\Howto\Repositories\VariationRepository;
use Charity\Howto\Repositories\UserVariationRepository;
use Product;
use View;
use \Illuminate\Database\Eloquent\Collection;

class WidgetUpsellController extends \BaseController {

    /**
    * Display a listing of the resource.
    * GET /widgetupsell
    *
    * @return Response
    */
    /**
    * @var UserRepository
    */
    private $userRepository;
    /**
    * @var ProductRepository
    */
    private $productRepository;
    /**
    * @var SessionService
    */
    private $sessionService;
    /**
    * @var VariantRepository
    */
    private $variationRepository;
    /**
    * @var ApplicationSettingRepository
    */
    private $applicationSettingRepository;
    /**
    * @var UserVariationRepository
    */
    private $userVariationRepository;

    public function __construct(UserRepository $userRepository,VariationRepository $variationRepository,ProductRepository $productRepository, SessionService $sessionService, ApplicationSettingRepository $applicationSettingRepository, UserVariationRepository $userVariationRepository)
    {
        $this->userRepository = $userRepository;
        $this->variationRepository =$variationRepository;
        $this->productRepository =$productRepository;
        $this->sessionService = $sessionService;
        $this->applicationSettingRepository = $applicationSettingRepository;
        $this->userVariationRepository = $userVariationRepository;
    }

    public function widgetUpsell()
    {
        $user = \Auth::user();
       
        if(empty($user))
            return [];
        $products = [];
        $currProductsIds = [];
        $currProductsNames = [];
        $productsInMembership = new Collection();
        $repo  = $this->applicationSettingRepository;
        $count = $repo->getValue('count')?:	5;
        $list  = $repo->getValue('list')?:	'widget-upsell';
        $time  = $repo->getValue('time')?:	4000;
        //Array of categories which are relevant to user based on purchases
        $categories   = $this->userRepository->widgetUpsellListGetCategories($user->id);
        $currProducts = $this->userRepository->getUserProductsWithResources($user->id, false)->products;
        $membershipLevel = $user->getActiveMembership();
        $memberVariations = $this->userVariationRepository->getVariationsByMemberId($user->id)->toArray();

        if (!empty($membershipLevel)) {
                $productsInMembership = $this->productRepository->getProductsAssignedToMembershipLevel($membershipLevel->id);
            }
        
        foreach ($currProducts as $value) {
            $currProductsIds[]  = $value->id;
            $currProductsNames[]= $value->name;
        }
        //Omit Variations acquired by membership
        foreach ($memberVariations as $value) {
            $currProductsIds[]  = $value['product_id'];
        }
        
        $variations = $this->variationRepository->findUpcomingByCategoryLimit($categories, $count, $currProductsIds, $currProductsNames);
        $count = $count - count($variations);

        if(!$count<=0){
            $products = $this->productRepository->getByCategoryAndTypeLimit($categories, Product::$_TYPE_RECORDED_WEBINAR, $count, $currProductsIds, $currProductsNames);
        }

        return View::make('pages.client.products.widget-upsell',[
            'variations'  => $variations,
            'products'    => $products,
            'list'        => $list,
            'time'        => $time,
            'user'        => $user,
            'productsInMembership' => $productsInMembership,
            'memberVariations' => $memberVariations,
        ]);        
    }
}
