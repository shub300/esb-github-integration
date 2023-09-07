<?php

namespace App\Listeners;

use \Aacotroneo\Saml2\Events\Saml2LoginEvent;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Session;

class LoginListener
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  Saml2LoginEvent  $event
     * @return void
     */
    public function handle(Saml2LoginEvent $event)
    {
        $user = $event->getSaml2User();
        $idp = $event->getSaml2Idp();

        $email = $user->getNameId();
        $name = '';
        $userData = [
            'id' => $user->getUserId(),
            'attributes' => $user->getAttributes(),
            'assertion' => $user->getRawSamlAssertion(),
            'sessionIndex' => $user->getSessionIndex(),
            'nameId' => $user->getNameId()
        ];

        if (count($userData['attributes'])) {
            foreach ($userData['attributes'] as $ak => $av) {
                if (strpos(strtolower($ak), 'name') !== false) {
                    if (isset($userData['attributes'][$ak][0]))
                        $name = $userData['attributes'][$ak][0];
                }
                if (strpos(strtolower($ak), 'displayname') !== false) {
                    if (isset($userData['attributes'][$ak][0]))
                        $name = $userData['attributes'][$ak][0];
                }
                if (strpos(strtolower($ak), 'emailaddress') !== false) {
                    if (isset($userData['attributes'][$ak][0])) {
                        $email = $userData['attributes'][$ak][0];
                    }
                }
            }
        }

        $auth_c =  \DB::table('es_auth_config')->where(['id' => $idp, 'auth_type' => 'SAML 2.0', 'status' => 1])->first();

        if ($email && $auth_c && $auth_c->organization_id) {
            //check if email already exists and fetch user
            $user_check = \App\User::where(['email' => $email, 'role' => 'user'])->first();
            $user_id = '';
            //if email doesn't exist, create new user

            if ($user_check === null) {
                $user_check = new \App\User;
                $user_check->name = $name; //sprintf('%s %s', $userData['attributes']['FirstName'][0], $userData['attributes']['LastName'][0]);
                $user_check->email = $email; //$userData['attributes']['emailAddress'][0];
                $user_check->password = bcrypt(\Str::random(5));
                $user_check->role = 'user';
                $user_check->confirmed = 1;
                $user_check->organization_id = $auth_c->organization_id;
                $user_check->save();
                $user_id = $user_check->id;
                // if ($user_id) {
                //     \DB::table('users')->where(['id' => $user_id])->update(['organization_id' => $user_id]);
                // }
            } else {
                $update_user_check = new \App\User;
                $user_id = $user_check->id;
                \App\User::where(['id' => $user_id])->update(['name' => $name, 'organization_id' => $auth_c->organization_id]);
            }

            \Auth::loginUsingId($user_id);
            $user_data = \Auth::user();

            if ($user_data) {
                setcookie('user_id', $user_data->id, time() + 3600, '/');
                Session::put('user_data', $user_data);
                //insert sessionIndex and nameId into session
                session(['user_data' => $user_data]);
            }
        } else {
            echo view('pages.saml.saml_error')->render();
            exit;
        }
    }
}
