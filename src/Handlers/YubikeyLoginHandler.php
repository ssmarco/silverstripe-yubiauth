<?php

namespace Firesphere\YubiAuth;

use Firesphere\BootstrapMFA\MFALoginHandler;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Security\LoginForm;
use SilverStripe\Security\Member;
use SilverStripe\Security\MemberAuthenticator\MemberLoginForm;
use SilverStripe\Security\Security;

/**
 * Class YubikeyLoginHandler
 */
class YubikeyLoginHandler extends MFALoginHandler
{
    private static $url_handlers = [
        'yubikey-authentication' => 'secondFactor'
    ];

    private static $allowed_actions = [
        'LoginForm',
        'dologin',
        'secondFactor',
        'yubikeyForm'
    ];

    /**
     * Return the MemberLoginForm form
     */
    public function LoginForm()
    {
        return YubikeyLoginForm::create(
            $this,
            get_class($this->authenticator),
            'LoginForm'
        );
    }

    /**
     * @param array $data
     * @param LoginForm|MemberLoginForm $form
     * @param HTTPRequest $request
     * @return \SilverStripe\Control\HTTPResponse
     */
    public function doLogin($data, MemberLoginForm $form, HTTPRequest $request)
    {
        $session = $request->getSession();
        if ($member = $this->checkLogin($data, $request, $message)) {
            $session->set('YubikeyLoginHandler.MemberID', $member->ID);
            $session->set('YubikeyLoginHandler.Data', $data);
            if (!empty($data['BackURL'])) {
                $session->set('YubikeyLoginHandler.BackURL', $data['BackURL']);
            }

            return $this->redirect($this->link('yubikey-authentication'));
        }
        $this->redirectBack();
    }

    public function secondFactor()
    {
        return ['Form' => $this->yubikeyForm()];
    }

    public function yubikeyForm()
    {
        return YubikeyForm::create($this, 'yubikeyForm');
    }

    public function MFAForm()
    {
        return $this->yubikeyForm();
    }

    /**
     * @param array $data
     * @param YubikeyForm $form
     * @param HTTPRequest $request
     * @return \SilverStripe\Control\HTTPResponse
     */
    public function validateYubikey($data, $form, $request)
    {
        $session = $request->getSession();
        $message = false;
        $memberData = $session->get('YubikeyLoginHandler.Data');
        $this->request['BackURL'] = !empty($memberData['BackURL']) ? $memberData['BackURL'] : '';
        $member = $this->authenticator->validateYubikey($data, $request, $message);
        if (!$member instanceof Member) {
            $field = Member::config()->get('unique_identifier_field');
            $tmpMember = Member::get()->filter([$field => $memberData['Email']])->first();
            $member = $this->authenticator->validateBackupCode($tmpMember, $data['yubiauth']);
        }

        if ($member instanceof Member) {
            $memberData = $session->get('YubikeyLoginHandler.Data');
            $this->performLogin($member, $memberData, $request);
            Security::setCurrentUser($member);
            $session->clear('YubikeyLoginHandler');

            return $this->redirectAfterSuccessfulLogin();
        }

        return $this->redirect($this->link());
    }
}
