<?php

namespace Firesphere\YubiAuth;

use Exception;
use Firesphere\BootstrapMFA\BootstrapMFAAuthenticator;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Security\Authenticator;
use SilverStripe\Security\Member;
use SilverStripe\Security\MemberAuthenticator\MemberAuthenticator;
use Yubikey\Response;
use Yubikey\Validate;

/**
 * Class YubikeyAuthenticator
 *
 * Enable Yubikey Authentication for SilverStripe CMS and member-protected pages.
 */
class YubikeyMemberAuthenticator extends BootstrapMFAAuthenticator
{

    /**
     * @var Validate
     */
    protected $yubiService;
    private $authenticatorName = 'yubiauth';

    /**
     * Name of this authenticator
     *
     * @return string
     */
    public static function get_name()
    {
        return _t('YubikeyAuthenticator.TITLE', 'Yubikey 2 factor login');
    }

    public function supportedServices()
    {
        // Bitwise-OR of all the supported services in this Authenticator, to make a bitmask
        return Authenticator::LOGIN | Authenticator::LOGOUT | Authenticator::CHANGE_PASSWORD
            | Authenticator::RESET_PASSWORD | Authenticator::CHECK_PASSWORD;
    }

    /**
     * @inheritdoc
     *
     * @param array $data
     * @param HTTPRequest $request
     * @param $message
     *
     * @return null|Member
     */
    public function validateYubikey($data, $request, &$message)
    {
        $memberID = $request->getSession()->get('YubikeyLoginHandler.MemberID');
        // First, let's see if we know the member
        /** @var Member $member */
        $member = Member::get()->filter(['ID' => $memberID])->first();

        // Continue if we have a valid member
        if ($member && $member instanceof Member) {

            // We do not have to check the YubiAuth for now.
            if (!$member->YubiAuthEnabled && empty($data['yubiauth'])) {
                return $this->authenticateNoYubikey($member);
            }

            // If we know the member, and it's YubiAuth enabled, continue.
            if (!empty($data['yubiauth'])) {
                /** @var Validate $service */
                $this->yubiService = Injector::inst()->createWithArgs(
                    Validate::class,
                    [
                        Environment::getEnv('YUBIAUTH_APIKEY'),
                        Environment::getEnv('YUBIAUTH_CLIENTID'),
                    ]
                );

                return $this->authenticateYubikey($data, $member);
            }
            $member->registerFailedLogin();
            $message = 'Yubikey Authentication error';
        }

        return null;
    }

    /**
     * Handle login if the user did not enter a Yubikey string.
     * Will break out and return NULL if the member should use their Yubikey
     *
     * @param  Member $member
     * @return ValidationResult|Member
     */
    private function authenticateNoYubikey($member)
    {
        ++$member->NoYubikeyCount;
        $member->write();
        $yubiAuthNoYubi = YubiAuthProvider::checkNoYubiAttempts($member);
        if ($yubiAuthNoYubi instanceof ValidationResult) {
            return $yubiAuthNoYubi;
        }

        return $member;
    }

    /**
     * Validate a member plus it's yubikey login. It compares the fingerprintt and after that,
     * tries to validate the Yubikey string
     *
     * @param  array $data
     * @param  Member $member
     * @return ValidationResult|Member
     */
    private function authenticateYubikey($data, $member)
    {
        if ($url = Config::inst()->get(self::class, 'AuthURL')) {
            $this->yubiService->setHost($url);
        }
        $yubiCode = QwertyConvertor::convertString($data['yubiauth']);
        $yubiFingerprint = substr($yubiCode, 0, -32);
        if ($member->Yubikey) {
            $validateYubiMember = YubiAuthProvider::validateYubikey($member, $yubiFingerprint);
            if ($validateYubiMember instanceof ValidationResult) {
                $member->registerFailedLogin();

                return $validateYubiMember;
            }
        }
        try {
            /** @var Response $result */
            $result = $this->yubiService->check($yubiCode);
            $this->updateMember($member, $yubiFingerprint);
        } catch (Exception $e) {
            $validationResult = ValidationResult::create();
            $validationResult->addError($e->getMessage());

            $member->registerFailedLogin();

            return $validationResult;
        }
        if ($result->success() === true) {
            $this->updateMember($member, $yubiFingerprint);

            return $member;
        }

        $validationResult = ValidationResult::create();
        $validationResult->addError(_t('YubikeyAuthenticator.ERROR', 'Yubikey authentication error'));
        $member->registerFailedLogin();

        return $validationResult;
    }

    /**
     * Update the member to forcefully enable YubiAuth
     * Also, register the Yubikey to the member.
     * Documentation:
     * https://developers.yubico.com/yubikey-val/Getting_Started_Writing_Clients.html
     *
     * @param Member $member
     * @param string $yubiString The Identifier String of the Yubikey
     */
    private function updateMember($member, $yubiString)
    {
        $member->registerSuccessfulLogin();
        $member->NoYubikeyCount = 0;

        if (!$member->YubiAuthEnabled) {
            $member->YubiAuthEnabled = true;
        }
        if (!$member->Yubikey) {
            $member->Yubikey = $yubiString;
        }
        $member->write();
    }


    public function getLoginHandler($link)
    {
        return YubikeyLoginHandler::create($link, $this);
    }
}
