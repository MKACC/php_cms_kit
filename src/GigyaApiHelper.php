<?php
/**
 * Created by PhpStorm.
 * User: Yaniv Aran-Shamir
 * Date: 4/5/16
 * Time: 5:06 PM
 */

namespace gigya;


use gigya\sdk\GSApiException;
use gigya\sdk\GSFactory;
use gigya\sdk\GSObject;
use gigya\sdk\SigUtils;
use gigya\user\GigyaUserFactory;

class GigyaApiHelper
{

    private $key;
    private $secret;
    private $apiKey;
    private $token;

    /**
     * GigyaApiHelper constructor.
     *
     * @param string $key    gigya app/user key
     * @param string $secret gigya app/user secret
     */
    public function __construct($key, $secret, $apiKey)
    {
        $this->key    = $key;
        $this->secret = $secret;
        $this->apiKey = $apiKey;
    }

    public function sendApiCall($method, $params)
    {
        $req = GSFactory::createGSRequestAppKey($this->apiKey, $this->key, $this->secret, $method,
            GSFactory::createGSObjectFromArray($params));

        return $req->send();
    }

    public function validateUid($uid, $uidSignature, $signatureTimestamp)
    {
        $params       = array(
            "UID"                => $uid,
            "UIDSignature"       => $uidSignature,
            "signatureTimestamp" => $signatureTimestamp
        );
        $res          = $this->sendApiCall("socialize.exchangeUIDSignature", $params);
        $sig          = $res->getData()->getString("UIDSignature", null);
        $sigTimestamp = $res->getData()->getString("signatureTimestamp", null);
        if (null !== $sig && null !== $sigTimestamp) {
            if (SigUtils::validateUserSignature($uid, $sigTimestamp, $this->secret, $sig)) {
                $user = $this->fetchGigyaAccount($uid);

                return $user;
            }
        }

        return false;
    }

    public function fetchGigyaAccount($uid, $include = null, $extraProfileFields = null)
    {
        if (null == $include) {
            $include
                = "identities-active,identities-all,loginIDs,emails,profile,data,password,lastLoginLocation,rba,
            regSource,irank";
        }
        if (null == $extraProfileFields) {
            $extraProfileFields
                = "languages,address,phones,education,honors,publications,patents,certifications,
            professionalHeadline,bio,industry,specialties,work,skills,religion,politicalView,interestedIn,
            relationshipStatus,hometown,favorites,followersCount,followingCount,username,locale,verified,timezone,likes,
            samlData";
        }
        $params       = array(
            "UID"                => $uid,
            "include"            => $include,
            "extraProfileFields" => $extraProfileFields
        );
        $res          = $this->sendApiCall("accounts.getAccountInfo", $params);
        $dataArray    = $res->getData()->serialize();
        $profileArray = $dataArray['profile'];
        $gigyaUser    = GigyaUserFactory::createGigyaUserFromArray($dataArray);
        $gigyaProfile = GigyaUserFactory::createGigyaProfileFromArray($profileArray);
        $gigyaUser->setProfile($gigyaProfile);

        return $gigyaUser;
    }

    public function getSiteSchema()
    {
        $params = new GSObject();
        $res    = $this->sendApiCall("accounts.getSchema", $params);
        //TODO: implement
    }

    public function isRaasEnabled($apiKey = null)
    {
        if (null === $apiKey) {
            $apiKey = $this->apiKey;
        }
        $params = GSFactory::createGSObjectFromArray(array("apiKey" => $apiKey));
        try {
            $this->sendApiCall("accounts.getGlobalConfig", $params);
            return true;
        } catch (GSApiException $e) {
            if ($e->getErrorCode() == 403036) {
                return false;
            }
            throwException($e);
        }
        return false;
    }

    // static

    static public function decrypt($str, $key = null)
    {
        if (null == $key) {
            $key = getenv("KEK");
        }
        $iv_size       = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC);
        $strDec        = base64_decode($str);
        $iv            = substr($strDec, 0, $iv_size);
        $text_only     = substr($strDec, $iv_size);
        $plaintext_dec = mcrypt_decrypt(MCRYPT_RIJNDAEL_128, $key,
            $text_only, MCRYPT_MODE_CBC, $iv);

        return $plaintext_dec;
    }

    static public function enc($str, $key = null)
    {
        if (null == $key) {
            $key = getenv("KEK");
        }
        $iv_size = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC);
        $iv      = mcrypt_create_iv($iv_size, MCRYPT_RAND);
        $crypt   = mcrypt_encrypt(MCRYPT_RIJNDAEL_128, $key, $str, MCRYPT_MODE_CBC, $iv);

        return trim(base64_encode($iv . $crypt));
    }

}