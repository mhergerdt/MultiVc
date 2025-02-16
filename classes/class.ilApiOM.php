<?php

require_once ("./Customizing/global/plugins/Services/Repository/RepositoryObject/MultiVc/classes/om/src/main/scripts/OmGateway.php");
require_once ("./Customizing/global/plugins/Services/Repository/RepositoryObject/MultiVc/classes/om/src/main/scripts/OmRestService.php");
require_once ("./Customizing/global/plugins/Services/Repository/RepositoryObject/MultiVc/classes/om/src/main/scripts/OmRoomManager.php");
require_once ("./Customizing/global/plugins/Services/Repository/RepositoryObject/MultiVc/classes/om/src/main/scripts/OmUserManager.php");
require_once("./Customizing/global/plugins/Services/Repository/RepositoryObject/MultiVc/classes/class.ilApiInterface.php");


class ilApiOM implements ilApiInterface
{
    const GROUPNAME = 'inno';
    const SERVERAPPNAME = 'openmeetings';
    const SERVERVERSION = '5';
    const ROOMTYPE = 'CONFERENCE'; // PRESENTATION INTERVIEW

    /** @var Container $dic */
    private $dic;

    /** @var ilObjMultiVc $object */
    private $object;

    /** @var ilMultiVcConfig $settings */
    private $settings;

    /** @var object|array $user */
    private $user;

    /** @var array $roomConf */
    private $roomConf;

    /** @var int $roomId */
    private $roomId;

    /** @var string $omUrl */
    private $omUrl;

    /** @var OmGateway $gateway */
    private $gateway;

    /** @var string $meetingId */
    private $meetingId = 0;

    /** @var array $pluginIniSet */
    private $pluginIniSet = [];

    /** @var bool $moderatedMeeting */
    private $moderatedMeeting;

    /** @var bool $meetingRecordable */
    private $meetingRecordable = false;


    public function __construct(ilObjMultiVcGUI $a_parent)
    {
        #parent::__construct($a_parent);
        //var_dump($this->getMeetingId()); exit;
        global $DIC; /** @var Container $DIC */
        $this->dic = $DIC;
        $this->object = $a_parent->object;
        $this->settings = ilMultiVcConfig::getInstance($this->object->getConnId());
        $this->pluginIniSet = ilApiMultiVC::setPluginIniSet($this->settings);
        $this->moderatedMeeting = $this->object->get_moderated();
        $this->setMeetingRecordable((bool)$this->object->isRecordingAllowed());

        $this->gateway = new OmGateway($this->getSoapConfig());
        try {
            $this->gateway->login();
        } catch (Exception $e) {}

        $this->setMeetingId();
        $this->setMeetingStartable();

    }

    /**
     * @return array
     */
    private function getSoapConfig() {
        $proto = $this->settings->getSvrProtocol()['public'];
        $host = str_replace($proto . '://', '', $this->settings->getSvrPublicUrl());
        return array(
            "protocol" => $proto,
            "host" => $host,
            "port" => $this->settings->getSvrPublicPort(),
            "context" => self::SERVERAPPNAME,
            "user" => $this->settings->getSvrUsername(),
            "pass" => $this->settings->getSvrSalt(),
            // set/create group. Members have access to rooms with assigned group
            "module" => $this->getMeetingId(),
            'debug' => (bool)$this->getPluginIniSet('debug')
        );
    }

    /**
     * Return the configParam to create a new room on ilObject creation
     * @param int|null $rmId
     * @return array
     */
    private function getRoomConfig(int $rmId = null) {
        $config = array(
            'id' => $rmId, // null to get a new room and its id
            'name' => $this->object->getTitle(),
            'comment' => $this->object->getDescription(),
            'type' => self::ROOMTYPE,
            //'capacity' => $this->settings->getMaxParticipants(),
            'appointment' => false,
            'isPublic' => false,
            //'demo' => false,
            //'closed' => false,
            'moderated' => $this->isModeratedMeeting(),
            'waitModerator' => $this->isModeratedMeeting(),
            'allowUserQuestions' => true,
            'allowRecording' => $this->isMeetingRecordable(),
            'waitRecording' => $this->isMeetingRecordable(),
            'audioOnly' => false,
            'chatHidden' => false === $this->object->get_withChat(),
            'externalId' => $this->object->getId(), // $this->getMeetingId()
            'files' => array(),
            'redirectUrl' => $this->dic->http()->request()->getUri() . '&amp;startOM=10',
            //'hiddenElements' => 'MICROPHONE_STATUS',
        );

        if( (bool)$this->settings->getMaxParticipants() ) {
            $config['capacity'] = $this->settings->getMaxParticipants();
        }
        return $config;
    }

    /**
     * @return object
     */
    private function getUserData()
    {
        #echo '<pre>'; var_dump($this->object->getRoomId()); exit;
        return (object)[
            'username' => $this->dic->user()->getLogin(),
            'firstname' => $this->dic->user()->getFirstname(),
            'lastname' => $this->dic->user()->getLastname(),
            'pictureUrl' => $this->getUserAvatar(),
            'email' => $this->dic->user()->getEmail(),
            'id' => $this->dic->user()->getId(),
            'roomOption' => [
                "roomId" => $this->object->getRoomId(),
                "moderator" => $this->isUserModerator(),
                "allowRecording" => $this->isMeetingRecordable()
            ]
        ];
    }

    private function getOmUser() {
        if( !$this->user ) {
            $this->user = $this->getUserData();
        }
        return $this->gateway->getUser(
            $this->user->username,
            $this->user->firstname,
            $this->user->lastname,
            $this->user->pictureUrl,
            $this->user->email,
            $this->user->id
        );
    }

    private function getOmUserDataByLiaUserId(int $userId = 0): array
    {
        $userId = 0 !== $userId ? $userId : $this->dic->user()->getId();
        $omUserDataArr = $this->gateway->getUsersByRoomId($this->object->getRoomId());
        foreach( $omUserDataArr as $key => $omUserData ) {
            if( (int)$omUserData['externalId'] === (int)$userId ) {
                return $omUserDataArr[$key];
            }
        }
        return [];
    }

    private function getUserLanguage(): int
    {
        $currUserLang = $this->dic->user()->getCurrentLanguage();
        $a_lang = array(
            'en' => 1
        , 'de' => 2
        , 'fr' => 4
        , 'it' => 5
        , 'pt' => 6
        , 'es' => 8
        );
        return false === array_search($currUserLang, $a_lang) ? 1 : $a_lang[$currUserLang];
    }

    private function getHash()
    {

        return $this->gateway->getSecureHash($this->getOmUser(), $this->user->roomOption);
    }

    private function setMeetingStartable(): void
    {
        switch (true) {
            case $this->isUserModerator() || $this->isUserAdmin():
            case !$this->isUserModerator() && $this->isMeetingRunning() && $this->isModeratorPresent() && $this->isValidAppointmentUser():
                $this->meetingStartable = true;
                break;

            default:
                $this->meetingStartable = false;
        }
    }






    ####################################################################################################################
    #### PUBLIC GETTERS & SETTERS
    ####################################################################################################################


    /**
     * @return string
     */
    public function getOmRoomUrl(): string
    {
        return $this->gateway->getUrl() ."/hash?secure=" . $this->getHash() . "&language=" . $this->dic->user()->getCurrentLanguage();
        // $language;   $this->getUserLanguage();
    }

    /**
     * @return int
     */
    public function createRoom(): int
    {
        #echo '<pre>'; var_dump($this->getSoapConfig()); exit;
        $rMgr = new OmRoomManager($this->getSoapConfig());
        #echo '<pre>'; var_dump($this->getRoomConfig()); exit;
        return $rMgr->update($this->getRoomConfig());
    }

    /**
     * @param int $rmId
     * @return int
     */
    public function updateRoom(int $rmId): int
    {
        $rMgr = new OmRoomManager($this->getSoapConfig());
        if( -1 !== $rMgr->get($rmId) ) {
            return $rMgr->update($this->getRoomConfig($rmId));
        } else {
            return $this->createRoom();
        }
    }

    public function getUsersByRoomId($roomId)
    {
        $uMgr = new OmUserManager($this->getSoapConfig());
        return $uMgr->getUsersByRoomId($roomId);
    }

    public function getRecordings(): array
    {
        /*
        if ( strtolower(ini_get('zlib.output_compression')) === 'on') {
            ini_set('zlib.output_compression', 'Off');
        }
        header('Content-disposition: attachment; filename=' . $filename);
        header('Content-type: video/' . $type);
        $url = $gateway->getUrl() . "/recordings/$type/" . getOmHash($gateway, array("recordingId" => $recId));
        readfile($url);
        */
        require_once "./Services/Calendar/classes/class.ilDateTime.php";

        $omUserId = $this->getOmUserDataByLiaUserId()['id'];
        //var_dump($this->getOmUserDataByLiaUserId()) ; exit;
        $recList = [];
        foreach ( $this->gateway->getRecordings() AS $key => $rec ) {
            if( isset($rec['ownerId']) && (int)$omUserId !== (int)$rec['ownerId']) {
                continue;
            }
            $ilStartTime = new ilDateTime(strtotime($rec['start']), IL_CAL_UNIX);
            $ilEndTime = new ilDateTime(strtotime($rec['end']), IL_CAL_UNIX);
            $recList[$rec['id']]['startTime'] = ilDatePresentation::formatDate($ilStartTime);
            $recList[$rec['id']]['endTime'] = ilDatePresentation::formatDate($ilEndTime); // $rec->getEndTime();
            $recList[$rec['id']]['playback'] = $this->gateway->getUrl() . "/recordings/mp4/" . $this->gateway->getSecureHash($this->getOmUser(), ["recordingId" => $rec['id']]);
            $recList[$rec['id']]['download'] = $this->gateway->getUrl() . "/recordings/mp4/" . $this->gateway->getSecureHash($this->getOmUser(), ["recordingId" => $rec['id']]);
            $recList[$rec['id']]['meetingId'] = $rec['externalType'];
        }
        return $recList;

    }
 
    public function deleteRecord( string $recId )
    {
        return $this->gateway->deleteRecording($recId);
    }

    public function isUserAdmin(): bool
    {
        return true;
    }

    public function isModeratorPresent(): bool
    {
        return true;
    }

    public function isMeetingStartable(): bool
    {
        return true;
    }

    public function isMeetingRunning(): bool
    {
        return true;
    }

    /**
     * @return bool
     */
    public function hasSessionObject(): bool
    {
        return !!$this->ilObjSession;
    }

    public function setRoomId($rmId) {
        $this->roomId = $rmId;
    }

    /**
     * Return Url
     * @return string
     */
    public function getUserAvatar(): string
    {
        $user_image = substr($this->dic->user()->getPersonalPicturePath($a_size = 'xsmall', $a_force_pic = true),2);
        if (substr($user_image,0,2) == './') {
            $user_image = substr($user_image, 2);
        }
        return ILIAS_HTTP_PATH.'/'.$user_image;
    }

    public function isValidAppointmentUser(): bool
    {
        return true;
        /*
         !$this->ilObjSession ||
            (
                !!$this->ilObjSession &&
                ilEventParticipants::_isRegistered($this->dic->user()->getId(), $this->ilObjSession->getId())
            );
        */
    }

    /**
     * @return bool
     */
    public function isUserModerator(): bool
    {
        return true; /* $this->userRole === 'moderator'; */
    }


    /**
     * @return string
     */
    public function getMeetingId(): string
    {
        return $this->meetingId;
    }

    private function setMeetingId(): void
    {
        global $ilIliasIniFile, $DIC; /** @var Container $DIC */

        $this->iliasDomain = $ilIliasIniFile->readVariable('server', 'http_path');
        $this->iliasDomain = preg_replace("/^(https:\/\/)|(http:\/\/)+/", "", $this->iliasDomain);

        $rawMeetingId = $this->iliasDomain . ';' . CLIENT_ID . ';' . $this->object->getId();

        if ( trim($this->settings->get_objIdsSpecial()) !== '') {
            $ArObjIdsSpecial = [];
            $rawIds = explode(",", $this->settings->get_objIdsSpecial());
            foreach ($rawIds as $id) {
                $id = trim($id);
                if (is_numeric($id)) {
                    array_push($ArObjIdsSpecial, $id);
                }
            }
            if (in_array($this->object->getId(), $ArObjIdsSpecial)) {
                $rawMeetingId .= 'r' . $this->object->getRefId();
            }
        }
        // $this->meetingId = md5($rawMeetingId);
        $this->meetingId = $rawMeetingId;
    }

    /**
     * @param string $value
     * @return string|null
     */
    public function getPluginIniSet(string $value = 'max_concurrent_users'): ?string
    {
        return isset($this->pluginIniSet[$value]) ? $this->pluginIniSet[$value] : null;
    }

    /**
     * @return bool
     */
    public function isModeratedMeeting(): bool
    {
        return $this->moderatedMeeting;
    }

    public function isMeetingRecordable(): bool
    {
        return $this->meetingRecordable;
    }

    /**
     * @param bool $meetingRecordable
     */
    public function setMeetingRecordable(bool $meetingRecordable): void
    {
        $this->meetingRecordable = $meetingRecordable;
    }



}