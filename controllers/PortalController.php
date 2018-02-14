<?php
namespace Admin;

use Carbon\Carbon;
use Charity\Howto\Services\SessionService;
use View;
use Input;
use Password;
use App;
use Redirect;
use Lang;

class PortalController extends \BaseController {

    /**
     * @var SessionService
     */
    private $sessionService;

    public function __construct(SessionService $sessionService)
    {
        $this->sessionService = $sessionService;
    }

    public function getDashboard()
    {
        return View::make('pages.admin.dashboard', [
            'authorCount' => App::make('Charity\Howto\Repositories\AuthorRepository')->count(),
            'productCount' => App::make('Charity\Howto\Repositories\ProductRepository')->count(),
            'categoryCount' => App::make('Charity\Howto\Repositories\CategoryRepository')->count(),
            'interestGroupCount' => App::make('Charity\Howto\Repositories\InterestGroupRepository')->count(),
            'userCount' => App::make('Charity\Howto\Repositories\UserRepository')->count(),
            'webformCount' => App::make('Charity\Howto\Repositories\WebformRepository')->count(),
        ]);
    }

    public function getPollTest()
    {
        /** @var \Charity\Howto\Services\CitrixApiService $citrixApi */
        $citrixApi = App::make('Charity\Howto\Services\CitrixApiService');

        $webinarId = Input::get('webinar_api_id');
        $webinarAccount = Input::get('webinar_api_account');
        $sessionId = Input::get('webinar_session');

        $pollQuestions = $sessionDetails = [];
        $sessions = ['' => 'Select A Session'];

        if ($webinarId && $webinarAccount) {
            try {
                // Session is selected.  Get all poll questions
                if ($sessionId) {
                    $pollQuestions = $citrixApi->getSessionPolls($webinarId, $sessionId, $webinarAccount);
                    $sessionDetails = $citrixApi->getSessionPerformance($webinarId, $sessionId, $webinarAccount);
                } else {
                    // Lets get the sessions for this webinar.
                    $webinarSessions = $citrixApi->getWebinarSessions($webinarId, $webinarAccount);
                    foreach ($webinarSessions as $webinarSession) {
                        $sessions[$webinarSession['sessionKey']] = Carbon::parse($webinarSession['startTime'])->format("l, F jS, Y @ h:ia") . ' - ' . Carbon::parse($webinarSession['endTime'])->format("h:ia") . " ({$webinarSession['sessionKey']})";
                    }
                }
            } catch (\Exception $e) {
                return Redirect::back()->with('errorMessage', $e->getMessage());
            }
        }

        return View::make('pages.admin.poll-test', [
            'webinar_api_id' => $webinarId,
            'webinar_api_account' => $webinarAccount,
            'sessions' => $sessions,
            'webinar_session' => $sessionId,
            'questions' => $pollQuestions,
            'details' => $sessionDetails,
        ]);
    }

    public function getLogin()
    {
        return View::make('pages.admin.portal.login');
    }

    public function postLogin()
    {
        try {
            $user = $this->sessionService->adminLogin(Input::get('email'), Input::get('password'), Input::get('remember'));
            return Redirect::route('admin.dashboard')->with('successMessage', "Welcome back {$user->first_name}!");
        } catch (\Exception $e) {
            return Redirect::back()->withInput()->with('errorMessage', $e->getMessage());
        }
    }

    public function getLogout()
    {
        $this->sessionService->logout();
        return Redirect::route('admin.portal.login')->with('successMessage', 'You have been logged out.');
    }

    public function getForgotPassword()
    {
        return View::make('pages.admin.portal.forgot-password');
    }

    public function postForgotPassword()
    {
        switch ($response = Password::remind(Input::only('email'), function($message)
        {
            $message->subject('Password Reset Request');
        }))
        {
            case Password::INVALID_USER:
                return Redirect::back()->with('errorMessage', Lang::get($response));

            case Password::REMINDER_SENT:
                return Redirect::back()->with('successMessage', Lang::get($response));
        }
    }

    public function getResetPassword($token = null)
    {
        if (is_null($token)) return Redirect::route('admin.portal.login')->with('errorMessage', 'No reset token provided');

        return View::make('pages.admin.portal.reset-password')->with('token', $token);
    }

    public function postResetPassword()
    {
        $credentials = Input::only('email', 'password', 'password_confirmation', 'token');

        $response = Password::reset($credentials, function($user, $password)
        {
            $user->password = $password;
            $user->save();
        });

        switch ($response)
        {
            case Password::INVALID_PASSWORD:
            case Password::INVALID_TOKEN:
            case Password::INVALID_USER:
                return Redirect::back()->with('errorMessage', Lang::get($response));

            case Password::PASSWORD_RESET:
                return Redirect::route('admin.portal.login')->with('successMessage', 'Your password has been updated!');
        }
    }

}
