<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use Session;

class AmazonCognitoController extends Controller
{
    public $COGNITO_REGION, $COGNITO_USER_POOL_ID, $COGNITO_APP_CLIENT_ID;

    public function __construct()
    {
        $this->COGNITO_REGION = 'us-east-1';
        $this->COGNITO_USER_POOL_ID = ((env('APP_ENV') == 'prod') ? 'us-east-1_vz1k2m0Tm' : 'us-east-1_HeMzQ7sup');
        $this->COGNITO_APP_CLIENT_ID = ((env('APP_ENV') == 'prod') ? '3l7nrbudpv0qj5rmgli0qn2r7o' : 'su1ievg8fhepsq8vccebm8ss3');
        //$this->COGNITO_USER_POOL_ID = 'us-east-1_HeMzQ7sup';
        //$this->COGNITO_APP_CLIENT_ID = 'su1ievg8fhepsq8vccebm8ss3';
    }

    public function ajaxValidateCognitoToken(Request $request)
    {
        try {
            if ($request->jwtToken) {
                list($header, $payload, $signature) = explode('.', $request->jwtToken);

                if ($header && $payload) {
                    // Retrieve the public key from AWS Cognito
                    $jwksUri = "https://cognito-idp." . $this->COGNITO_REGION . ".amazonaws.com/" . $this->COGNITO_USER_POOL_ID . "/.well-known/jwks.json";
                    $jwks = json_decode(file_get_contents($jwksUri));

                    // Find the public key that matches the JWT token's key ID
                    $kid = json_decode(base64_decode($header))->kid;

                    $publicKey = null;
                    foreach ($jwks->keys as $key) {
                        if ($key->kid == $kid) {
                            $publicKey = $key;
                            break;
                        }
                    }

                    // Verify the signature of the JWT token using the public key
                    if ($publicKey) {
                        $decoded = json_decode(base64_decode($payload));
                        // Verify the claims in the JWT token
                        if ($decoded->aud != $this->COGNITO_APP_CLIENT_ID || $decoded->iss != "https://cognito-idp." . $this->COGNITO_REGION . ".amazonaws.com/" . $this->COGNITO_USER_POOL_ID || $decoded->exp < time()) {
                            return response()->json(
                                ['token_status' => 'Invalid']
                            );
                        }
                    } else {
                        // Public key not found
                        return response()->json(
                            ['token_status' => 'Invalid']
                        );
                    }

                    return response()->json(
                        ['token_status' => 'Valid']
                    );
                }
                return response()->json(
                    ['token_status' => 'Invalid']
                );
            }

            return response()->json(
                ['token_status' => 'Invalid']
            );
        } catch (\Exception $e) {
            return response()->json(
                ['token_status' => 'Invalid']
            );
        }

        return response()->json(
            ['token_status' => 'Invalid']
        );
    }

    public function ValidateCognitoToken($jwtToken)
    {
        $validate_status = false;
        try {
            if ($jwtToken) {
                list($header, $payload, $signature) = explode('.', $jwtToken);

                if ($header && $payload) {
                    // Retrieve the public key from AWS Cognito
                    $jwksUri = "https://cognito-idp." . $this->COGNITO_REGION . ".amazonaws.com/" . $this->COGNITO_USER_POOL_ID . "/.well-known/jwks.json";
                    $jwks = json_decode(file_get_contents($jwksUri));

                    // Find the public key that matches the JWT token's key ID
                    $kid = json_decode(base64_decode($header))->kid;

                    $publicKey = null;
                    foreach ($jwks->keys as $key) {
                        if ($key->kid == $kid) {
                            $publicKey = $key;
                            break;
                        }
                    }

                    // Verify the signature of the JWT token using the public key
                    if ($publicKey) {
                        $decoded = json_decode(base64_decode($payload));
                        // Verify the claims in the JWT token
                        if ($decoded->aud != $this->COGNITO_APP_CLIENT_ID || $decoded->iss != "https://cognito-idp." . $this->COGNITO_REGION . ".amazonaws.com/" . $this->COGNITO_USER_POOL_ID || $decoded->exp < time()) {
                            $validate_status = false;
                        } else {
                            $validate_status = true;
                        }
                    } else {
                        // Public key not found
                        $validate_status = false;
                    }
                }
            }
        } catch (\Exception $e) {
            $validate_status = false;
            //return redirect()->to('jwt-token-expired');
        }
        return $validate_status;
    }

    public function getJwtTokenExpiredView(Request $request)
    {
        if (Session::has('user_data')) {
            Session::forget('user_data');
        }
        $request->session()->flush();
        echo '<script>localStorage.removeItem("jwtToken");</script>';

        return view('pages.jwt_token_expired');
    }
}
