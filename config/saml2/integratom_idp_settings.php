<?php
// If you choose to use ENV vars to define these values, give this IdP its own env var names
// so you can define different values for each IdP, all starting with 'SAML2_'.$this_idp_env_id
$this_idp_env_id = 'INTEGRATOM';

//This is variable is for simplesaml example only.
// For real IdP, you must set the url values in the 'idp' config to conform to the IdP's real urls.
// $idp_host = env('SAML2_'.$this_idp_env_id.'_IDP_HOST', 'http://localidp.integrato.com');//'http://localhost/new-saml2/public/');
$sp_host = env('APP_URL');
$idp_host = env('SAML2_'.$this_idp_env_id.'_IDP_HOST', env('IDP_BASE_URL'));//'http://localhost/new-saml2/public/');
return $settings = array(

    /*****
     * One Login Settings
     */

    // If 'strict' is True, then the PHP Toolkit will reject unsigned
    // or unencrypted messages if it expects them signed or encrypted
    // Also will reject the messages if not strictly follow the SAML
    // standard: Destination, NameId, Conditions ... are validated too.
    'strict' => false, //@todo: make this depend on laravel config

    // Enable debug mode (to print errors)
    'debug' => env('APP_DEBUG', false),

    // Service Provider Data that we are deploying
    'sp' => array(

        // Specifies constraints on the name identifier to be used to
        // represent the requested subject.
        // Take a look on lib/Saml2/Constants.php to see the NameIdFormat supported
        'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',

        // Usually x509cert and privateKey of the SP are provided by files placed at
        // the certs folder. But we can also provide them with the following parameters
        'x509cert' => env('SAML2_'.$this_idp_env_id.'_SP_x509',''),
        'privateKey' => env('SAML2_'.$this_idp_env_id.'_SP_PRIVATEKEY','MIICdgIBADANBgkqhkiG9w0BAQEFAASCAmAwggJcAgEAAoGBAKt3K0U0A/RMJzHzSwYT4cfy50pkqT+J3B+0XVMeTPoTnLsZfyToU/GKeK4/tyb+G5boR7OeMnVTsHDgz8bkpFwkLcEScEu8PvMZYH2vL84aCKr9OtoNA/JJ6jPRBgWiLxNhH2rVQWvEikopaYiEYqNRBE2z3PpCNTR4e5M6+fyRAgMBAAECgYAwMJ7tpS/TUi/V3w3f2GilXIRaqS3UGLfQBU4RVTvHMQnkn8bXJaCqCwPd3TRpdhNk90VnmveNeAbnwpdCy/HRKek5MGuiSRjQ+2+U421/FaLbhGfBDg/FwVp6mAJ7lC5lIqU/hU1YZuSdhrudHCjherJGAwjfCVigzlu35D793QJBANJV36HtyrEd8UEJEXxqWd+ZSqf6SsaaWSErtpmv9BK5KK3zwUdIhrQNVXGdBQpr0Ic/XjCOrORqtF5DT1/URtsCQQDQsPaAPtz8r9CukW5oeHK5sTnmRHHlUZHqi1CbxF/UHtnapsK+yUu+n+QzlCSGEe5MrzraeubxotTXymgFW/gDAkBSl1G2/e6nWcCP7wWkuwYLXOAJ0ahnD9iLw+RxuLu4Vmh41cxBN2NddBbnA+ckzm0VjnZnzr5o+tVUZk3WrT4dAkAwIL+Yb+by93D+8VcvDKgYnwClVB+YLSmjl6FtaupWtw6y2EaNTUsEmUc9hequaLA2Sysde76K92xyn6FBqyYVAkEAnRMnryYfk6CX3jIiqVq/Dt7E5uuYjv1fvguOsAk7opwVTGwhbX+gf5JDwDCna7c+/EGz0qoO1PrDuLHy9kjOfg=='),

        // Identifier (URI) of the SP entity.
        // Leave blank to use the '{idpName}_metadata' route, e.g. 'test_metadata'.
        'entityId' => env('SAML2_'.$this_idp_env_id.'_SP_ENTITYID',$sp_host . '/saml2/integratom/metadata'),

        // Specifies info about where and how the <AuthnResponse> message MUST be
        // returned to the requester, in this case our SP.
        'assertionConsumerService' => array(
            // URL Location where the <Response> from the IdP will be returned,
            // using HTTP-POST binding.
            // Leave blank to use the '{idpName}_acs' route, e.g. 'test_acs'
            'url' => $sp_host . '/saml2/integratom/acs',
        ),
        // Specifies info about where and how the <Logout Response> message MUST be
        // returned to the requester, in this case our SP.
        // Remove this part to not include any URL Location in the metadata.
        'singleLogoutService' => array(
            // URL Location where the <Response> from the IdP will be returned,
            // using HTTP-Redirect binding.
            // Leave blank to use the '{idpName}_sls' route, e.g. 'test_sls'
            'url' => $sp_host . '/saml2/integratom/sls',
        ),
    ),

    // Identity Provider Data that we want connect with our SP
    'idp' => array(
        // Identifier of the IdP entity  (must be a URI)
        'entityId' => env('SAML2_'.$this_idp_env_id.'_IDP_ENTITYID', $sp_host . '/saml2/'.strtolower($this_idp_env_id).'/metadata'),
        // SSO endpoint info of the IdP. (Authentication Request protocol)
        'singleSignOnService' => array(
            // URL Target of the IdP where the SP will send the Authentication Request Message,
            // using HTTP-Redirect binding.
            'url' => env('SAML2_'.$this_idp_env_id.'_IDP_SSO_URL', $idp_host . '/login'),
        ),
        // SLO endpoint info of the IdP.
        'singleLogoutService' => array(
            // URL Location of the IdP where the SP will send the SLO Request,
            // using HTTP-Redirect binding.
            'url' => env('SAML2_'.$this_idp_env_id.'_IDP_SL_URL', $idp_host . '/logout'),
        ),
        // Public x509 certificate of the IdP
        'x509cert' => env('SAML2_'.$this_idp_env_id.'_IDP_x509', 'MIICgjCCAeugAwIBAgIBADANBgkqhkiG9w0BAQsFADBeMQswCQYDVQQGEwJpbjEVMBMGA1UECAwMQ2hoYXR0aXNnYXJoMRQwEgYDVQQKDAtjb25zdGFjbG91ZDESMBAGA1UEAwwJaW50ZWdyYXRvMQ4wDAYDVQQHDAVLb3JiYTAeFw0yMDEyMjMxMTQ1NTNaFw00MDEyMTgxMTQ1NTNaMF4xCzAJBgNVBAYTAmluMRUwEwYDVQQIDAxDaGhhdHRpc2dhcmgxFDASBgNVBAoMC2NvbnN0YWNsb3VkMRIwEAYDVQQDDAlpbnRlZ3JhdG8xDjAMBgNVBAcMBUtvcmJhMIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQCrdytFNAP0TCcx80sGE+HH8udKZKk/idwftF1THkz6E5y7GX8k6FPxiniuP7cm/huW6EeznjJ1U7Bw4M/G5KRcJC3BEnBLvD7zGWB9ry/OGgiq/TraDQPySeoz0QYFoi8TYR9q1UFrxIpKKWmIhGKjUQRNs9z6QjU0eHuTOvn8kQIDAQABo1AwTjAdBgNVHQ4EFgQUHCDREkaD5kSNmxFHCm6f9rHa5JgwHwYDVR0jBBgwFoAUHCDREkaD5kSNmxFHCm6f9rHa5JgwDAYDVR0TBAUwAwEB/zANBgkqhkiG9w0BAQsFAAOBgQBYzKh1aCoOU0gvf0TzIpFGl+cBv7Tb4ENlsHNlSfaTmBkikfhkDheofEnuroW8EPWHaCsBKIVYRdLjD86EXSkfd/XN/dQD789uKBnEIyS6w6VDTEMgd9xYCu2+6wsq5Vvm7wWh3Smisiqpr8nrCy4qDWnrGM8gHmOrwBvNoCIQAw=='),
        //'MIID/TCCAuWgAwIBAgIJAI4R3WyjjmB1MA0GCSqGSIb3DQEBCwUAMIGUMQswCQYDVQQGEwJBUjEVMBMGA1UECAwMQnVlbm9zIEFpcmVzMRUwEwYDVQQHDAxCdWVub3MgQWlyZXMxDDAKBgNVBAoMA1NJVTERMA8GA1UECwwIU2lzdGVtYXMxFDASBgNVBAMMC09yZy5TaXUuQ29tMSAwHgYJKoZIhvcNAQkBFhFhZG1pbmlAc2l1LmVkdS5hcjAeFw0xNDEyMDExNDM2MjVaFw0yNDExMzAxNDM2MjVaMIGUMQswCQYDVQQGEwJBUjEVMBMGA1UECAwMQnVlbm9zIEFpcmVzMRUwEwYDVQQHDAxCdWVub3MgQWlyZXMxDDAKBgNVBAoMA1NJVTERMA8GA1UECwwIU2lzdGVtYXMxFDASBgNVBAMMC09yZy5TaXUuQ29tMSAwHgYJKoZIhvcNAQkBFhFhZG1pbmlAc2l1LmVkdS5hcjCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBAMbzW/EpEv+qqZzfT1Buwjg9nnNNVrxkCfuR9fQiQw2tSouS5X37W5h7RmchRt54wsm046PDKtbSz1NpZT2GkmHN37yALW2lY7MyVUC7itv9vDAUsFr0EfKIdCKgxCKjrzkZ5ImbNvjxf7eA77PPGJnQ/UwXY7W+cvLkirp0K5uWpDk+nac5W0JXOCFR1BpPUJRbz2jFIEHyChRt7nsJZH6ejzNqK9lABEC76htNy1Ll/D3tUoPaqo8VlKW3N3MZE0DB9O7g65DmZIIlFqkaMH3ALd8adodJtOvqfDU/A6SxuwMfwDYPjoucykGDu1etRZ7dF2gd+W+1Pn7yizPT1q8CAwEAAaNQME4wHQYDVR0OBBYEFPsn8tUHN8XXf23ig5Qro3beP8BuMB8GA1UdIwQYMBaAFPsn8tUHN8XXf23ig5Qro3beP8BuMAwGA1UdEwQFMAMBAf8wDQYJKoZIhvcNAQELBQADggEBAGu60odWFiK+DkQekozGnlpNBQz5lQ/bwmOWdktnQj6HYXu43e7sh9oZWArLYHEOyMUekKQAxOK51vbTHzzw66BZU91/nqvaOBfkJyZKGfluHbD0/hfOl/D5kONqI9kyTu4wkLQcYGyuIi75CJs15uA03FSuULQdY/Liv+czS/XYDyvtSLnu43VuAQWN321PQNhuGueIaLJANb2C5qq5ilTBUw6PxY9Z+vtMjAjTJGKEkE/tQs7CvzLPKXX3KTD9lIILmX5yUC3dLgjVKi1KGDqNApYGOMtjr5eoxPQrqDBmyx3flcy0dQTdLXud3UjWVW3N0PYgJtw5yBsS74QTGD4='),
        /*
         *  Instead of use the whole x509cert you can use a fingerprint
         *  (openssl x509 -noout -fingerprint -in "idp.crt" to generate it)
         */
        // 'certFingerprint' => '',
    ),



    /***
     *
     *  OneLogin advanced settings
     *
     *
     */
    // Security settings
    'security' => array(

        /** signatures and encryptions offered */

        // Indicates that the nameID of the <samlp:logoutRequest> sent by this SP
        // will be encrypted.
        'nameIdEncrypted' => false,

        // Indicates whether the <samlp:AuthnRequest> messages sent by this SP
        // will be signed.              [The Metadata of the SP will offer this info]
        'authnRequestsSigned' => false,

        // Indicates whether the <samlp:logoutRequest> messages sent by this SP
        // will be signed.
        'logoutRequestSigned' => false,

        // Indicates whether the <samlp:logoutResponse> messages sent by this SP
        // will be signed.
        'logoutResponseSigned' => false,

        /* Sign the Metadata
         False || True (use sp certs) || array (
                                                    keyFileName => 'metadata.key',
                                                    certFileName => 'metadata.crt'
                                                )
        */
        'signMetadata' => false,


        /** signatures and encryptions required **/

        // Indicates a requirement for the <samlp:Response>, <samlp:LogoutRequest> and
        // <samlp:LogoutResponse> elements received by this SP to be signed.
        'wantMessagesSigned' => false,

        // Indicates a requirement for the <saml:Assertion> elements received by
        // this SP to be signed.        [The Metadata of the SP will offer this info]
        'wantAssertionsSigned' => false,

        // Indicates a requirement for the NameID received by
        // this SP to be encrypted.
        'wantNameIdEncrypted' => false,

        // Authentication context.
        // Set to false and no AuthContext will be sent in the AuthNRequest,
        // Set true or don't present thi parameter and you will get an AuthContext 'exact' 'urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport'
        // Set an array with the possible auth context values: array ('urn:oasis:names:tc:SAML:2.0:ac:classes:Password', 'urn:oasis:names:tc:SAML:2.0:ac:classes:X509'),
        'requestedAuthnContext' => true,
    ),

    // Contact information template, it is recommended to suply a technical and support contacts
    // 'contactPerson' => array(
    //     'technical' => array(
    //         'givenName' => 'Integtao',
    //         'emailAddress' => 'info@integtao.com'
    //     ),
    //     'support' => array(
    //         'givenName' => 'Integtao',
    //         'emailAddress' => 'info@integtao.com'
    //     ),
    // ),

    // Organization information template, the info in en_US lang is recomended, add more if required
    // 'organization' => array(
    //     'en-US' => array(
    //         'name' => 'Integrato',
    //         'displayname' => 'Display Name',
    //         'url' => 'http://url'
    //     ),
    // ),

/* Interoperable SAML 2.0 Web Browser SSO Profile [saml2int]   http://saml2int.org/profile/current

   'authnRequestsSigned' => false,    // SP SHOULD NOT sign the <samlp:AuthnRequest>,
                                      // MUST NOT assume that the IdP validates the sign
   'wantAssertionsSigned' => true,
   'wantAssertionsEncrypted' => true, // MUST be enabled if SSL/HTTPs is disabled
   'wantNameIdEncrypted' => false,
*/

);
