<?php

namespace Ankou;

use cURL\Request;
use Exception;
use FML\ManiaLink;
use FML\ManiaLinks;
use FML\Controls\Quad;
use FML\Script\Script;
use FML\Controls\Audio;
use FML\Controls\Frame;
use FML\Controls\Gauge;
use FML\Controls\Label;
use \ManiaControl\Logger;
use ManiaControl\ManiaControl;
use ManiaControl\Players\Player;
use ManiaControl\Plugins\Plugin;
use ManiaControl\Players\PlayerManager;
use ManiaControl\Files\AsyncHttpRequest;
use FML\Controls\Quads\Quad_Icons64x64_1;
use ManiaControl\Callbacks\TimerListener;
use ManiaControl\ManiaExchange\MXMapInfo;
use ManiaControl\Settings\SettingManager;
use ManiaControl\Commands\CommandListener;
use ManiaControl\Callbacks\CallbackManager;
use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Manialinks\ManialinkManager;
use ManiaControl\Manialinks\SidebarMenuManager;
use ManiaControl\Communication\CommunicationListener;
use ManiaControl\Manialinks\SidebarMenuEntryListener;
use ManiaControl\Manialinks\ManialinkPageAnswerListener;
use ManiaControl\Maps\MapManager;
use MCTeam\CustomVotesPlugin;

abstract class StringParserState
{
    const NULL      = 0;
    const INBAN     = 1;
    const INPICK    = 2;
}

class VetoSequenceNode
{
    public $team; //A B X
    public $pick = false;

    public function __construct($team, $pick)
    {
        $this->team = $team;
        $this->pick = $pick;
    }
}



/**
 * VetoManager
 *
 * @author  Ankou
 * @version 0.98
 */
class VetoManagerPlugin implements CallbackListener, CommandListener, TimerListener, CommunicationListener, Plugin, ManialinkPageAnswerListener, SidebarMenuEntryListener
{
    const ID            = 185;
    const VERSION       = 1.1;
    const NAME          = 'VetoManager';
    const AUTHOR        = 'Ankou';
    const DESCRIPTION   = 'Veto manager, can be connected to other plugins';

    //Debug
    const __DEBUG__CLICK = false;
    const __DEBUG__COMMAND = false;
    const __DEBUG__MINIMIZE = false;
    const __DEBUG__MULTIPLECALL = false;


    //Settings
    const SETTING_LIST_X        = "List pos X";
    const SETTING_LIST_Y        = "List pos Y";
    const SETTING_LIST_WIDTH    = "List width";
    const SETTING_LIST_HEIGHT   = "List height";
    const SETTING_STANDALONE    = "Is standAlone";
    const SETTING_VETOSTRING    = "Veto string (only for standAlone)";
    const SETTING_ALLOWUSERS    = "Allow non-admin to start veto";
    const SETTING_BACKSTYLE     = "Backgroud Style";
    const SETTING_BACKSUBSTYLE  = "Backgroud SubStyle";
    const SETTING_ENABLELOGS    = "Log veto result";
    const SETTING_STARTWITHVOTE = "Use vote system to start (Require CustomVotesPlugin)";
    const SETTING_CHOOSEVOTE    = "Use vote system for each choose";

    const SETTING_CHOOSETIME    = "Time in seconds for each choose";
    const SETTING_USESOUNDS     = "Use sounds";
    const SETTING_MINIMIZED_X   = "Minimized pos X";
    const SETTING_MINIMIZED_Y   = "Minimized pos Y";
    const SETTING_REDUCETIMER   = "Reduce Timer";
    const SETTING_REDUCERATIO   = "Reduce Ratio";

    const SETTING_USE_THGR      = "Use thumbnailGrid interface";
    const SETTING_THGR_X        = "ThumbnailGrid X";
    const SETTING_THGR_Y        = "ThumbnailGrid Y";
    const SETTING_THGR_WIDTH    = "ThumbnailGrid Width";
    const SETTING_THGR_HEIGHT   = "ThumbnailGrid Height";
    const SETTING_THGR_IMGW     = "ThumbnailGrid Image Width";
    const SETTING_THGR_IMHE     = "ThumbnailGrid Image Height";
    const SETTING_THGR_OFFSET   = "ThumbnailGrid Offset";

    const SETTING_SHOW_BANNED   = "Show banned maps";
    const SETTING_SHOW_STATE    = "Show veto state";
    const SETTING_LOGTCHAT_STEP = "Log steps in tchat";

    const SETTING_PUSHRESULT    = "Push result to an API";
    const SETTING_PUSHURL       = "Push API URL";

    const SETTING_MAPLIST       = "Map list";
    const SETTING_RANDOMLIST    = "Random List";

    protected $reduceRatio;
    protected $isStandAlone = false;
    protected $allowUsers = true;
    protected $enableLogs = false;
    protected $vetoString = "";

    protected $listX = -75;
    protected $listY = 70;
    protected $listWidth = 150;
    protected $listHeight = 120;

    protected $useThumbnailGrid = false;
    protected $thumbGrid_X = -145;
    protected $thumbGrid_Y = 76;
    protected $thumbGrid_Width = 270; //100
    protected $thumbGrid_Height = 115;  //80
    protected $thumbGrid_ImageWidth = 95;
    protected $thumbGrid_ImageHeight = 75;
    protected $thumbGrid_Offset = 3;



    protected $backStyle = "";
    protected $backSubStyle = "";
    protected $startWithVote = false;
    protected $chooseVote = false;
    protected $chooseTime = 60;
    protected $useSounds = true;
    protected $minimizedX = 131;
    protected $minimizedY = -85;
    protected $reduceTimer = -1;
    protected $showBanned = true;
    protected $showState = false;

    protected $pushResult = false;
    protected $pushResultApiUrl = "http://127.0.0.1/resultApi/push";

    protected $mapList = "";
    protected $randomList = "";

    protected $isDebug = self::__DEBUG__CLICK || self::__DEBUG__COMMAND || self::__DEBUG__MINIMIZE;

    //ManiaLink
    const ML_VETOLIST_ID        = "VetoManager.List";
    const ML_VETOMINIMIZE_ID    = "VetoManager.Minimized";
    const ML_THUMNAILSGRID_ID   = "VetoManager.ThumbnailsGrid";
    const ML_PRELOAD            = "VetoManager.PreloadImages";
    const ACT_VETO_MAXIMISE     = "VetoManager.Maximise";
    const ACT_VETO_MINIMISE     = "VetoManager.Minimise";
    const ACT_VETOLIST_SELECT   = "VetoManager.List.";
    const ACT_VETO_START        = "VetoManager.Start";

    const ICON_MENU             = "VetoManager.MenuIcon";
    const MLID_ICON             = "VetoManager.IconWidgetId";
    const MLID_SOUNDS           = "VetoManager.Sound";


    //Log tchat Type
    const LOGTYPE_INFO = 1;
    const LOGTYPE_BAN = 2;
    const LOGTYPE_PICK = 3;



    protected $maxNbBan = -1;
    protected $maxNbPick = -1;
    protected $vetoSequence = [];
    protected $currentVetoNodeIndex = -1;
    protected $vetoList = [];
    protected $windowStateByPlayer = [];    //["login" => true/false] (true = maximised)
    protected $newSequenceNotified = [];    //["login" => true/false] (true = notification)
    protected $availableMaps = [];
    protected $availableRandomMaps = [];
    protected $vetoStarted = false;
    protected $currentVetoString = null;
    protected $currentVoteVeto = [];
    protected $reduceTime = -1;
    protected $logTchatSteps = false;

    //callbacks
    protected $onVetoFinishedListeners = [];
    protected $checkMasterPluginStart = [];
    protected $customVoteVetoStart = [];

    //voteChoose :
    protected $voteExpireTime = -1;


    protected $thumbnailMaps = [];
    protected $maps = [];
    protected $randomMaps = [];

    /** @var ManiaControl $maniaControl */
    protected $maniaControl = null;



    /**
     * @see \ManiaControl\Plugins\Plugin::load()
     */
    public function load(ManiaControl $maniaControl)
    {
        $this->maniaControl = $maniaControl;
        

        //settings
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_STANDALONE, true);
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_ALLOWUSERS, true);
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_ENABLELOGS, false);
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_VETOSTRING, "-A-BB-AA-B+B+A+X", "allowed chars : -+ABX (- = Ban; + = Pick; A = Team A; B = Team B; X = Auto last map)");
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_LIST_X, -75, "VetoList X position");
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_LIST_Y, 70, "VetoList Y position");
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_LIST_WIDTH, 150, "VetoList Width");
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_LIST_HEIGHT, 120, "VetoList Height");
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_BACKSTYLE, "Bgs1");
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_BACKSUBSTYLE, "BgWindow2");
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_STARTWITHVOTE, false, "This setting require CustomVotesPlugin enabled !");
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_CHOOSEVOTE, false);
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_CHOOSETIME, 60, "Time in seconds");
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_USESOUNDS, true, "Use sounds");
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_MINIMIZED_X, 131, "Minimized X position");
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_MINIMIZED_Y, -85, "Minimized Y position");
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_REDUCETIMER, 0, "Reduce Timer");
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_REDUCERATIO, 1.0, "Reduce Ratio");

        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_THGR_X, $this->thumbGrid_X);
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_USE_THGR, $this->useThumbnailGrid);
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_THGR_Y, $this->thumbGrid_Y);
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_THGR_WIDTH, $this->thumbGrid_Width);
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_THGR_HEIGHT, $this->thumbGrid_Height);
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_THGR_IMGW, $this->thumbGrid_ImageWidth);
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_THGR_IMHE, $this->thumbGrid_ImageHeight);
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_THGR_OFFSET, $this->thumbGrid_Offset);
        
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_SHOW_BANNED, $this->showBanned, "Show banned maps");
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_LOGTCHAT_STEP, $this->logTchatSteps, "Log each steps in tchat");
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_SHOW_STATE, $this->showState);
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_PUSHRESULT, $this->pushResult);
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_PUSHURL, $this->pushResultApiUrl);
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_MAPLIST, $this->mapList, "mxid1,mxid2,...");
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_RANDOMLIST, $this->randomList, "mxid1,mxid2,...");

        $this->updateSettings();


        //ManiaLink callbacks
        $this->maniaControl->getManialinkManager()->registerManialinkPageAnswerListener(self::ACT_VETO_MAXIMISE, $this, 'handleVetoMaximise');
        $this->maniaControl->getManialinkManager()->registerManialinkPageAnswerListener(self::ACT_VETO_MINIMISE, $this, 'handleVetoMinimize');
        $this->maniaControl->getManialinkManager()->registerManialinkPageAnswerListener(self::ACT_VETO_START, $this, 'handleVetoStart');
        $this->maniaControl->getCallbackManager()->registerCallbackListener(CallbackManager::CB_MP_PLAYERMANIALINKPAGEANSWER, $this, 'handleManialinkPageAnswer');



        //CallBacks
        $this->maniaControl->getCallbackManager()->registerCallbackListener(SettingManager::CB_SETTING_CHANGED, $this, 'updateSettings');
        $this->maniaControl->getCallbackManager()->registerCallbackListener(PlayerManager::CB_PLAYERCONNECT, $this, 'handlePlayerConnect');
        $this->maniaControl->getCallbackManager()->registerCallbackListener(PlayerManager::CB_PLAYERINFOCHANGED, $this, 'handlePlayerInfosChanged');
        $this->maniaControl->getCallbackManager()->registerCallbackListener(MapManager::CB_MAPS_UPDATED, $this, 'updateSettings');


        $this->maniaControl->getTimerManager()->registerTimerListening($this, 'handle1Second', 1000);


        //Commands
        $this->maniaControl->getCommandManager()->registerCommandListener("startveto", $this, "onCommandStartVeto", false, "Start the veto");
        $this->maniaControl->getCommandManager()->registerCommandListener("startveto", $this, "onCommandStartVetoAdmin", true, "Start the veto");
        $this->maniaControl->getCommandManager()->registerCommandListener("cancelveto", $this, "onCommandCancelVeto", true, "Cancel the veto");
        if (self::__DEBUG__COMMAND)
            $this->maniaControl->getCommandManager()->registerCommandListener("randomveto", $this, "onCommandRandomVeto", true, "Random a veto turn");

        if (self::__DEBUG__MULTIPLECALL)
            $this->maniaControl->getCommandManager()->registerCommandListener("multicall", $this, "onCommandMultiCall", true, "Call multiple executeAction()");

        if (self::__DEBUG__MINIMIZE)
            $this->maniaControl->getCommandManager()->registerCommandListener("minimizeveto", $this, "onCommandMinimizeVeto", true, "Minimize UI in other team");
    }

    public function fetchMaplistByMixedUidIdString($string)
    {
        $titlePrefix = $this->maniaControl->getMapManager()->getCurrentMap()->getGame();
        $url = "https://api.mania-exchange.com/{$titlePrefix}/maps/?ids={$string}";
      
        $asyncHttpRequest = new AsyncHttpRequest($this->maniaControl, $url);
        $asyncHttpRequest->setContentType(AsyncHttpRequest::CONTENT_TYPE_JSON);
        $maps = $this->thumbnailMaps;
        $asyncHttpRequest->setCallable(function ($mapInfo, $error) use ($titlePrefix, $url)
        {
            if ($error)
            {
                trigger_error("Error: '{$error}' for Url '{$url}'");
                return;
            }
            if (!$mapInfo)
                return;

            $mxMapList = json_decode($mapInfo);
            if ($mxMapList === null)
            {
                trigger_error("Can't decode searched JSON Data from Url '{$url}'");
                return;
            }

            foreach ($mxMapList as $map)
            {
                if ($map)
                {
                    $mxMapObject = new MXMapInfo($titlePrefix, $map);
                    if ($mxMapObject)
                        array_push($this->thumbnailMaps, $mxMapObject);
                }
            }
            $this->maniaControl->getMapManager()->getMXManager()->updateMapObjectsWithManiaExchangeIds($this->thumbnailMaps);
        });

        $asyncHttpRequest->getData();
    }
    public function updateSettings()
    {
      

        $this->isStandAlone             = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_STANDALONE);
        $this->allowUsers               = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_ALLOWUSERS);
        $this->enableLogs               = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_ENABLELOGS);
        $this->vetoString               = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_VETOSTRING);
        $this->listX                    = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_LIST_X);
        $this->listY                    = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_LIST_Y);
        $this->listWidth                = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_LIST_WIDTH);
        $this->listHeight               = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_LIST_HEIGHT);
        $this->backStyle                = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_BACKSTYLE);
        $this->backSubStyle             = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_BACKSUBSTYLE);
        $this->startWithVote            = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_STARTWITHVOTE);
        $this->chooseVote               = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_CHOOSEVOTE);
        $this->chooseTime               = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_CHOOSETIME);
        $this->useSounds                = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_USESOUNDS);
        $this->minimizedX               = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_MINIMIZED_X);
        $this->minimizedY               = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_MINIMIZED_Y);
        $this->reduceTimer              = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_REDUCETIMER);
        $this->reduceRatio              = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_REDUCERATIO);
        $this->useThumbnailGrid         = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_USE_THGR);
        $this->thumbGrid_X              = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_THGR_X);
        $this->thumbGrid_Y              = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_THGR_Y);
        $this->thumbGrid_Width          = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_THGR_WIDTH);
        $this->thumbGrid_Height         = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_THGR_HEIGHT);
        $this->thumbGrid_ImageWidth     = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_THGR_IMGW);
        $this->thumbGrid_ImageHeight    = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_THGR_IMHE);
        $this->thumbGrid_Offset         = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_THGR_OFFSET);
        $this->showBanned               = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_SHOW_BANNED);
        $this->logTchatSteps            = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_LOGTCHAT_STEP);
        $this->showState                = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_SHOW_STATE);
        $this->pushResult               = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_PUSHRESULT);
        $this->pushResultApiUrl         = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_PUSHURL);
        $this->mapList                  = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_MAPLIST);
        $this->randomList               = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_RANDOMLIST);
        $this->initMapList();
        $this->initRandomMapList();
        if ($this->isStandAlone)
        {
            if (!($this->validateString($this->vetoString, $message)))
            {
                $this->maniaControl->getChat()->sendErrorToAdmins("Invalid veto string '{$this->vetoString}' ($message)");
            }
        }

        if ($this->allowUsers)
        {
            $this->maniaControl->getCommandManager()->enableCommand("startveto", false, $this);
            $this->maniaControl->getManialinkManager()->getSidebarMenuManager()->addMenuEntry(SidebarMenuManager::ORDER_PLAYER_MENU + 5, self::ICON_MENU, $this, 'showIcon');
        }
        else
        {
            $this->maniaControl->getCommandManager()->disableCommand("startveto", false, $this);
            $this->maniaControl->getManialinkManager()->getSidebarMenuManager()->deleteMenuEntry($this, self::ICON_MENU);
            $this->maniaControl->getManialinkManager()->hideManialink(self::ICON_MENU);
            $this->maniaControl->getManialinkManager()->hideManialink(self::MLID_ICON);
        }

        if ($this->startWithVote)
        {
            if (!($this->maniaControl->getPluginManager()->isPluginActive("MCTeam\\CustomVotesPlugin")))
            {
                $this->maniaControl->getChat()->sendErrorToAdmins("CustomVotesPlugin is disabled, VetoPlugin require it !");
                //   $this->startWithVote = false;
            }
        }

      
        $mapIdString = "";
        $maps = $this->maniaControl->getMapManager()->getMaps();
        $appendString = "";
        foreach ($maps as $map)
        {
            $appendString .= $map->uid . ',';
        }
        $mapIdString = substr($appendString, 0, -1);
        $this->fetchMaplistByMixedUidIdString($mapIdString);

        $this->maniaControl->getManialinkManager()->hideManialink(self::ML_PRELOAD, $this->maniaControl->getPlayerManager()->getPlayers());
        $this->buildPreloadImages(null);


    }

    protected function countPlayersByTeam($team = 0)
    {
        $cpt = 0;
        $players = $this->maniaControl->getClient()->getPlayerList();
        foreach ($players as $player)
        {
            if ($player->teamId == $team && !($player->spectatorStatus))
            {
                ++$cpt;
            }
        }
        return $cpt;
    }


    /**
     * For VoteVeto map tick
     */
    public function handle1Second()
    {
        if (!$this->vetoStarted || !$this->chooseVote)
            return;


        $timeUntil = $this->voteExpireTime - time();
        if ($this->reduceTimer > 0)
        {
            $team = ($this->vetoSequence[$this->currentVetoNodeIndex]->team == "B"); //1 or 0
            $nbPlayers = $this->countPlayersByTeam($team);

            if ($nbPlayers == 0 || count($this->currentVoteVeto) / $nbPlayers >= $this->reduceRatio)
            {
                if ($this->reduceTime == -1)
                {
                    $this->reduceTime = $this->reduceTimer;
                }
                else
                {
                    --$this->reduceTime;
                }
                if ($this->reduceTime < $timeUntil)
                    $timeUntil = $this->reduceTime;
            }
        }


        if ($timeUntil > 0)
        {
            $this->showManialink($this->vetoSequence[$this->currentVetoNodeIndex], null, false, $timeUntil);
        }
        else
        {
            $maxVoteMap = [];
            $votedList = $this->countVoteMaps();
            $maxVote = $this->getMaxVoteMap();
            foreach ($votedList as $map => $nb)
            {
                if ($nb == $maxVote)
                    $maxVoteMap[] = $map;
            }
            switch (count($maxVoteMap))
            {
                case 0:
                    $this->executeAction(null, "RANDOM");
                    break;

                case 1:
                    $this->executeAction(null, $maxVoteMap[0]);
                    break;

                default:
                    $val = rand(0, count($maxVoteMap) - 1);
                    $this->executeAction(null, $maxVoteMap[$val]);
                    break;
            }
        }
    }

    /**
     * Search map id from UID
     */
    protected function getAvailableId($map)
    {
        $id = 0;
        foreach ($this->availableMaps as $amap)
        {
            if ($amap == $map)
                return $id;

            ++$id;
        }

        return -1;
    }

    /**
     * Execute a click on list or at the end of vote Timer (pick or ban)
     */
    protected function executeAction($player, $map)
    {
        if($player != null 
        && (($player->isSpectator || ($this->vetoSequence[$this->currentVetoNodeIndex]->team == "A" && $player->teamId != 0) 
        || ($this->vetoSequence[$this->currentVetoNodeIndex]->team == "B" &&  $player->teamId == 0)) 
        && !self::__DEBUG__CLICK))
        {
            return;
        }
        if ($map == "RANDOM")
        {
            $val = rand(0, count($this->availableMaps) - 1);
            $map = $this->availableMaps[$val];
            unset($this->availableMaps[$val]);
            $this->availableMaps = array_values($this->availableMaps);
        }
        elseif($map == "AUTORANDOM")
        {
            if(count($this->availableRandomMaps) < $this->maniaControl->getMapManager()->getMapsCount())
            {
                $val = rand(0, count($this->availableRandomMaps) - 1);
                $map = $this->availableRandomMaps[$val];
                unset($this->availableRandomMaps[$val]);
                $this->availableRandomMaps = array_values($this->availableRandomMaps);
            }
            else
            {
                $val = rand(0, count($this->availableMaps) - 1);
                $map = $this->availableMaps[$val];
                unset($this->availableMaps[$val]);
                $this->availableMaps = array_values($this->availableRandomMaps);
            }
        }
        else
        {
            $id = $this->getAvailableId($map);
            if ($id == -1)
            {
                $this->maniaControl->getChat()->sendError("Fatal error VetoPlugin : getAvailableId() returns -1 !");
                $this->cancelVeto("", null);
                return;
            }
            unset($this->availableMaps[$id]);
            $this->availableMaps = array_values($this->availableMaps);
        }
        $mapName = $this->maniaControl->getMapManager()->getMapByUid($map)->name;
        
        
        $team = $this->vetoSequence[$this->currentVetoNodeIndex]->team;
        if ($this->vetoSequence[$this->currentVetoNodeIndex]->pick)
        {
            
            if($team == "R")
                $this->logVetoTchat(self::LOGTYPE_PICK, $mapName . ' $z(Random)');
            else
                $this->logVetoTchat(self::LOGTYPE_PICK, $mapName . ' $z(by team ' . $team . ")");

            $this->vetoList[$map] = [
                "team"      => $team,
                "type"      => "pick",
                "timestamp" => time()

            ];
        }
        else
        {
            if($team == "R")
                $this->logVetoTchat(self::LOGTYPE_PICK, $mapName . ' $z(Random)');
            else
                $this->logVetoTchat(self::LOGTYPE_BAN, $mapName . ' $z(by team ' . $team . ")");
            $this->vetoList[$map] = [
                "team"      => $team,
                "type"      => "ban",
                "timestamp" => time()

            ];
        }

        //Next sequence & Update UI
        $this->reduceTime = $this->reduceTimer;
        $this->maniaControl->getManialinkManager()->hideManialink(self::ML_VETOLIST_ID);
        $this->maniaControl->getManialinkManager()->hideManialink(self::ML_VETOMINIMIZE_ID);
        $this->maniaControl->getManialinkManager()->hideManialink(self::ML_THUMNAILSGRID_ID);



        if (++$this->currentVetoNodeIndex < count($this->vetoSequence))
        {
            if ($this->vetoSequence[$this->currentVetoNodeIndex]->team == "X")
            {
                $this->executeAction(null, "RANDOM");
            }
            elseif($this->vetoSequence[$this->currentVetoNodeIndex]->team == "R")
            {
                $this->executeAction(null, "AUTORANDOM");
            }
            else
            {
                $timeUntil = 0;
                if ($this->chooseVote)
                {
                    $this->voteExpireTime = time() + $this->chooseTime;
                    $this->reduceTime = $this->reduceTimer;
                    $timeUntil = $this->voteExpireTime - time();
                    $this->currentVoteVeto = [];
                }
                //$this->showManialink($this->vetoSequence[$this->currentVetoNodeIndex], $player, true, $timeUntil);
                $this->showManialink($this->vetoSequence[$this->currentVetoNodeIndex], null, true, $timeUntil);
                $this->buildSoundNewNode($player);
            }
        }
        else
        {
            $this->vetoStarted = false;
            $this->reduceTime = -1;
            $resultJson = $this->buildVetoResult();
            if ($this->enableLogs)
                $this->logVetoJson(json_encode($resultJson));

            if($this->pushResult)
                $this->pushJsonResultToApi();
            
            $this->triggerOnVetoFinished($resultJson);
            $this->setMapQueue();
            //$this->buildSoundEndVeto($player);
            $this->buildSoundEndVeto(null);
        }
    }

    protected function pushJsonResultToApi()
    {
        $array = $this->maniaControl->getClient()->getModeScriptSettings();
        $eventNumber    = $array["S_StatsEventNumber"];
        if(isset($array["S_StatsEventName"]))
            $eventName      = $array["S_StatsEventName"];
        else
            $eventName      = "undefined";

        $matchNumber    = $array["S_StatsMatchNumber"];


        $serverName = $this->maniaControl->getClient()->getServerName();
        $serverLogin = $this->maniaControl->getServer()->login;

        $players = [];
        foreach($this->maniaControl->getPlayerManager()->getPlayers() as $player)
        {
            $players[] = [
                "login"         => $player->login,
                "is_spectator"  => $player->isSpectator,
                "team_number"   => $player->teamId +1
            ];
        }



        $json = (object)[
            "server_login"  => $serverLogin,
            "server_name"   => $serverName,
            "timestamp"     => time(),
            "event"         => $eventName,
            "event_number"  => $eventNumber,
            "match_number"  => $matchNumber,
            "players"       => $players,
            "veto_string"   => $this->vetoString,
            "veto"          => $this->buildVetoResult(),

        ];

        $asyncHttpRequest = new AsyncHttpRequest($this->maniaControl, $this->pushResultApiUrl);
		$asyncHttpRequest->setContent(json_encode($json));
		$asyncHttpRequest->setContentType($asyncHttpRequest::CONTENT_TYPE_JSON);
        $asyncHttpRequest->setCallable(function ($json, $error) use (
			&$player
		) {
        });
        $asyncHttpRequest->postData();
        echo json_encode($json);

    }


    /**
     * Build the veto result Json
     */
    protected function buildVetoResult()
    {
        $data = [];
        $maps = $this->getAvailableMaps();
     //   var_dump($maps);
      //  var_dump($this->vetoList);

        foreach ($this->vetoList as $map => $infos)
        {
            if($infos["team"] == "A")
                $num = 0;
            else 
                $num = 1;

            $mapObj = $this->maniaControl->getMapManager()->getMapByUid($map);
            $mxId = 0;
            if(isset($mapObj->mx))
                $mxId = $mapObj->mx->id;

            $data[] = (object)[
                "map_name"          => $mapObj->name,
                "map_uid"           => $map,
                "action"            => $infos["type"],
                "team_letter"       => $infos["team"],
                "timestamp"         => $infos["timestamp"],
                "team_number"       => $num + 1,
                "mxid"              => $mxId
            ];
        }
        return $data;
    }

    /**
     * @param int $type : const LOGTYPE_*
     * @param string $message
     */
    protected function logVetoTchat($type, $message)
    {
        if(!($this->logTchatSteps))
            return;


        switch($type)
        {
            case self::LOGTYPE_INFO:
                $this->maniaControl->getChat()->sendInformation("VetoInfo : $message");
                break;
            case self::LOGTYPE_BAN:
                $this->maniaControl->getChat()->sendChat('$f00Ban : $z' . $message);
                break;
            case self::LOGTYPE_PICK:
                $this->maniaControl->getChat()->sendChat('$0f0Pick : $z' . $message);
                break;
            default:
                $this->maniaControl->getChat()->sendErrorToAdmins("Programming error, bad LOGTYPE in logVetoTchat method ! (value : $type)");
                break;
        }
    }

    protected function logVetoJson($json)
    {
        Logger::log($json);
    }

    /**
     * Called by executeAction at the end of the Veto, add maps in the queue
     */
    public function setMapQueue()
    {
        $pickList = $this->getPickList();
        if (count($pickList) > 0)
        {
            $this->maniaControl->getMapManager()->getMapQueue()->clearMapQueue();
            $start = 0;
            if ($this->maniaControl->getMapManager()->getCurrentMap()->uid == $pickList[0])
            {
                $start = 1;
                $this->maniaControl->getClient()->restartMap();
            }
            for ($i = $start; $i < count($pickList); ++$i)
            {
                $this->maniaControl->getMapManager()->getMapQueue()->serverAddMapToMapQueue($pickList[$i]);
            }
            if ($start == 0)
                $this->maniaControl->getClient()->nextMap();
        }
    }

    /**
     * @return array Picked maps array
     */
    protected function getPickList()
    {
        $result = [];
        foreach ($this->vetoList as $map => $data)
        {
            if ($data["type"] == "pick")
            {
                $result[] = $map;
            }
        }
        return $result;
    }

    protected function getPickedOrder($p_map)
    {
        $ordr = 1;
        foreach ($this->vetoList as $map => $data)
        {
            if ($data["type"] == "pick")
            {
                if ($p_map == $map)
                    return $ordr;
                else
                    ++$ordr;
            }
        }
        return -1;
    }


    /**
     * @return bool True if string ok (+update the veto sequence if string is good)
     */
    protected function validateString($string, &$message)
    {
        if (strlen($string) == 0)
        {
            $message = "Empty string";
            return false;
        }
        $this->initMapList();
        $this->initRandomMapList();

  //      $nbMaps = $this->maniaControl->getMapManager()->getMapsCount();
        if(count($this->maps) == $this->maniaControl->getMapManager()->getMapsCount())
        {
            $nbMaps = count($this->maps);
        }
        else
        {
            $nbMaps = count($this->maps);
            if(count($this->randomMaps) != $this->maniaControl->getMapManager()->getMapsCount())
            {
                $nbMaps += count($this->randomMaps);
            }
        }

        $strNbMaps = 0;
        $strNbBan = 0;
        $strNbPick = 0;
        $state = StringParserState::NULL;
        $autoLastMap = false;

        $tempSequence = [];

        for ($i = 0; $i < strlen($string); ++$i)
        {
            switch (strtoupper($string[$i]))
            {
                case "-":
                    if ($state == StringParserState::INPICK)
                    {
                        $message =  "Ban sequence cannot be after pick sequence";
                        return false;
                    }

                    $state = StringParserState::INBAN;
                    break;

                case "+":
                    $state = StringParserState::INPICK;
                    break;

                case "A":
                case "B":
                case "R":
                    switch ($state)
                    {
                        case StringParserState::NULL:
                            $message =  "Cannot select team, without start sequence";
                            return false;
                        case StringParserState::INBAN:
                            $tempSequence[] = new VetoSequenceNode($string[$i], false);
                            ++$strNbBan;
                            break;
                        case StringParserState::INPICK:
                            $tempSequence[] = new VetoSequenceNode($string[$i], true);
                            ++$strNbPick;
                            break;
                    }
                    ++$strNbMaps;
                    break;
                case "X":
                    if ($state != StringParserState::INPICK)
                    {
                        $message =  "X should be used in pick sequence";
                        return false;
                    }

                    ++$strNbMaps;
                    ++$strNbPick;
                    $autoLastMap = true;
                    $tempSequence[] = new VetoSequenceNode("X", true);
                    break;
                default:
                    return false;
            }
        }

        if ($autoLastMap && $nbMaps != $strNbMaps)   //if the last map is auto, the veto string have to contains exactly the same number of map than server maplist
        {
            $message =  "Auto map can't be used, bad maplist number";
            return false;
        }
        if ($nbMaps < $strNbMaps)
        {
            $message =  "Pick and ban number > mapList";
            return false;
        }

        $this->currentVetoString = $string;
        $this->vetoSequence = $tempSequence;
        $this->maxNbBan = $strNbBan;
        $this->maxNbPick = $strNbPick;
        $this->vetoList = [];
        $this->availableMaps = [];
        
        foreach ($this->maps as $map)
        {
            $this->availableMaps[] = $map->uid;
        }
        $this->availableRandomMaps = [];
        
        foreach ($this->randomMaps as $map)
        {
            $this->availableRandomMaps[] = $map->uid;
        }
        return true;
    }


    private function initMapList()
    {
        $this->maps = $this->maniaControl->getMapManager()->getMaps();
        if($this->mapList == null || empty($this->mapList))
            return;

        $selection = explode(",", $this->mapList);

        $this->maps= array_filter($this->maps, function ($map) use($selection)
        {
            $mapObj = $this->maniaControl->getMapManager()->getMapByUid($map->uid);
            $mxId = 0;
            if(isset($mapObj->mx))
                $mxId = $mapObj->mx->id;
            return($map->mx != null && in_array($mxId, $selection));
        });

    }
    private function initRandomMapList()
    {
        $this->randomMaps = $this->maniaControl->getMapManager()->getMaps();
        if($this->randomList == null || empty($this->randomList))
            return;

        $selection = explode(",", $this->randomList);

        $this->randomMaps= array_filter($this->randomMaps, function ($map) use($selection)
        {
            $mapObj = $this->maniaControl->getMapManager()->getMapByUid($map->uid);
            $mxId = 0;
            if(isset($mapObj->mx))
                $mxId = $mapObj->mx->id;
            return($map->mx != null && in_array($mxId, $selection));
        });

    }

    private function getAvailableMaps()
    {
      //  $maps = $this->maniaControl->getMapManager()->getMaps();
        $maps = $this->maps;
        if($this->showBanned)
            return $maps;


        $return = [];
        foreach($maps as $map)
        {
            if (key_exists($map->uid, $this->vetoList))
            {
                if ($this->vetoList[$map->uid]["type"] !== "ban")
                    $return[] = $map;

            }
            else
            {
                $return[] = $map;

            }

        }
        return $return;
    }

    //=================================================================================================================================================================
    //==[OnCommands]===============================================================================================================================================================
    //=================================================================================================================================================================
 
    public function onCommandMultiCall(array $chatCallback, Player $player)
    {
        $this->executeAction($player, "RANDOM");
        $this->executeAction($player, "RANDOM");
        $this->executeAction($player, "RANDOM");
        $this->executeAction($player, "RANDOM");
    }

    public function onCommandMinimizeVeto(array $chatCallback, Player $player)
    {
        $players = $this->maniaControl->getPlayerManager()->getPlayers(true);
        foreach ($players as $p)
        {
            if ($player->teamId != $p->teamId)
            {
                $this->handleVetoMinimize([], $p);
                break;
            }
        }
    }


    public function onCommandRandomVeto(array $chatCallback, Player $player)
    {
        if ($this->vetoStarted)
        {
            if ($this->chooseVote && count($this->availableMaps) > 1)
            {
                for ($i = 0; $i < 2; ++$i)
                {
                    $maps = $this->availableMaps;
                    $val = rand(0, count($maps) - 1);
                    $this->currentVoteVeto["FAKE$i"] = $maps[$val];
                }
            }
            else
            {
                $this->handleManialinkPageAnswer([
                    "ManiaPlanet.PlayerManialinkPageAnswer",
                    [
                        123,
                        "*fakeplayer2*",
                        "VetoManager.List.RANDOM",
                        []
                    ]
                ]);

                //$this->executeAction($player, "RANDOM");
            }
        }
    }

    public function onCommandStartVeto(array $chatCallback, Player $player)
    {
        if ($this->isStandAlone)
            $this->startVeto($this->vetoString, $player);
    }
    public function onCommandStartVetoAdmin(array $chatCallback, Player $player)
    {
        if ($this->isStandAlone)
            $this->startVeto($this->vetoString, $player, true);
    }



    /**
     * Start the veto 
     * @param string $vetoString formated string veto
     * @param Player $player Who start the veto
     * @param bool $force if false -> start vote; if true -> start the veto
     */
    public function startVeto($vetoString, $player, $force = false)
    {
        if ($this->vetoStarted)
        {
            $this->maniaControl->getChat()->sendErrorToAdmins("Veto already started");
            return;
        }
        $message = "";
        if (!($this->validateString($vetoString, $message)))
        {
            $this->maniaControl->getChat()->sendErrorToAdmins("Invalid veto string '{$this->vetoString}' ($message)");
        }
        if ($this->startWithVote && !$force)
        {
            try
            {
                if (!($this->checkMasterPluginAllowedTostart()))
                {
                    $this->maniaControl->getChat()->sendError("The master plugin refused this action", $player->login);
                    return;
                }


                $votePlugin = $this->maniaControl->getPluginManager()->getPlugin("MCTeam\\CustomVotesPlugin");
                if ($votePlugin != null)
                {
                    $votePlugin->defineVote("veto_start", "Start veto");
                    $votePlugin->startVote($player, "veto_start", [$this, "handleVoteStart"]);

                    return;
                }
            }
            catch (\Exception $e)
            {
                $this->maniaControl->getChat()->sendErrorToAdmins("Unable to start vetoVote (" . $e->getMessage() . ")");
            }
        }


        if (count($this->vetoSequence) > 0)
        {
            if ($this->isDebug)
                $this->maniaControl->getChat()->sendErrorToAdmins("VetoManager is in Debug mode !!");

            $this->currentVetoNodeIndex = 0;
            $this->windowStateByPlayer = [];    //reset window state
            $this->newSequenceNotified = [];
            $this->currentVoteVeto = [];
            $this->vetoList = [];
            $this->vetoStarted = true;
            if ($this->chooseVote)
            {
                $this->voteExpireTime = time() + $this->chooseTime;
            }
            $this->buildSoundStarVeto();
            $this->showManialink($this->vetoSequence[$this->currentVetoNodeIndex], null, true, 999);
        }
    }

    /**
     * onFinished vote
     * @param bool $result, if result = true vote ok
     */
    public function handleVoteStart($result)
    {
        if ($result == true)
            $this->startVeto($this->currentVetoString, null, true);
    }

    public function onCommandCancelVeto(array $chatCallback, Player $player)
    {
        $this->cancelVeto($this->vetoString, $player);
    }

    public function cancelVeto($string, $player)
    {
        $this->currentVetoNodeIndex = 0;
        $this->vetoStarted = false;
        $this->vetoList = [];
        $this->reduceTime = -1;
        $this->currentVoteVeto = [];
        $this->maniaControl->getManialinkManager()->hideManialink(self::ML_VETOLIST_ID);
        $this->maniaControl->getManialinkManager()->hideManialink(self::ML_VETOMINIMIZE_ID);
        $this->maniaControl->getManialinkManager()->hideManialink(self::ML_THUMNAILSGRID_ID);
        $this->windowStateByPlayer = []; //reset window state
        $this->newSequenceNotified = [];
        if ($player == null)
            $this->maniaControl->getChat()->sendInformation("Veto cancelled by another plugin");
        else
            if($player == $this)
                $this->maniaControl->getChat()->sendInformation("Veto cancelled (error)");
            else
                $this->maniaControl->getChat()->sendInformation("Veto cancelled by " . $player->nickname);
    }

    //=================================================================================================================================================================
    //==[ManiaLink]====================================================================================================================================================
    //=================================================================================================================================================================


    /**
     * Select the manialink to show
     * @param VoteSequenceNode $sequenceNode current vote sequence
     * @param Player $forcedPlayer
     * @param bool $forcedNew If true -> notification if minimized
     * @param int $time time to vote
     */
    public function showManialink($sequenceNode, $forcedPlayer = null, $forcedNew = true, $time = 999)
    {
        if ($forcedPlayer != null)
        {
            $this->showManiaLinkByLogin($sequenceNode, $forcedPlayer, $forcedNew, $time);
        }
        else
        {
            $players = $this->maniaControl->getPlayerManager()->getPlayers();
            foreach ($players as $player)
            {
                $this->showManiaLinkByLogin($sequenceNode, $player, $forcedNew, $time);
            }
        }
    }

    /**
     * Select the manialink to show
     * @param VoteSequenceNode $sequenceNode current vote sequence
     * @param Player $forcedPlayer
     * @param bool $forcedNew If true -> notification if minimized
     * @param int $time time to vote
     */
    public function showManiaLinkByLogin($sequenceNode, $player, $forcedNew = true, $time = 999)
    {
        if ($player == null)
        {
            $this->maniaControl->getChat()->sendErrorToAdmins("[Veto] Unable to display a manialink to a NULL player !");
            return;
        }

        $cantClic = ($player->isSpectator || ($sequenceNode->team == "A" && $player->teamId != 0) || ($sequenceNode->team == "B" && $player->teamId == 0)) && !self::__DEBUG__CLICK;
        if (isset($this->windowStateByPlayer[$player->login]))
        {
            if ($this->windowStateByPlayer[$player->login])
            {
                if ($this->useThumbnailGrid)
                    $this->buildThumbnailGridManialink($sequenceNode, $player->login, $cantClic, $time);
                else
                    $this->buildListManialink($sequenceNode, $player->login, $cantClic, $time);
            }
            else
            {
                if ($forcedNew)
                    $this->newSequenceNotified[$player->login] = true;

                if (isset($this->newSequenceNotified[$player->login]) && $this->newSequenceNotified[$player->login])
                    $this->buildMinimizedManialink($player, true);
                else
                    $this->buildMinimizedManialink($player, false);
            }
        }
        else
        {
            if ($this->useThumbnailGrid)
                $this->buildThumbnailGridManialink($sequenceNode, $player->login, $cantClic, $time);
            else
                $this->buildListManialink($sequenceNode, $player->login, $cantClic, $time);
        }

        //Sounds =============================================
        if ($this->chooseVote && $time < 10)
        {
            $this->buildSoundTickManialing($player);
        }
    }

    protected function buildSoundManaiaLink($player = null, $sound = "StartRound")
    {
        if ($this->useSounds)
        {
            $ml = '<?xml version="1.0" encoding="utf-8" standalone="yes"?>
                <manialink id="VetoManager.Sound" version="3" name="VetoManager.Sound">
                <script><!--
                main()
                {
                PlayUiSound(CMlScriptIngame::EUISound::' . $sound . ', 1, 1.);
                while (True) { yield; }
                }
                --></script>
                </manialink>';

            if ($player != null)
            {
                $this->maniaControl->getManialinkManager()->sendManialink($ml, $player->login);
            }
            else
            {
                $players = $this->maniaControl->getPlayerManager()->getPlayers();
                foreach ($players as $player)
                {
                    $this->maniaControl->getManialinkManager()->sendManialink($ml, $player->login);
                }
            }
        }
    }


    protected function buildSoundNewNode($player = null)
    {
        if ($this->chooseVote)
        {
            $this->buildSoundManaiaLink($player, "StartRound");
        }
    }
    protected function buildSoundEndVeto($player = null)
    {
        if ($this->chooseVote)
        {
            $this->buildSoundManaiaLink($player, "StartRound");
        }
    }
    protected function buildSoundStarVeto($player = null)
    {
        if ($this->chooseVote)
        {
            $this->buildSoundManaiaLink($player, "StartMatch");
        }
    }
    protected function buildSoundTickManialing($player)
    {
        if ($this->chooseVote)
        {
            $this->buildSoundManaiaLink($player, "Custom4");
        }
    }

    public function buildMinimizedManialink($login, $new = false)
    {
        $manialink = new ManiaLink(self::ML_VETOMINIMIZE_ID);

        $frame = new Frame();
        $manialink->addChild($frame);
        $frame->setPosition(0, 0, ManialinkManager::MAIN_MANIALINK_Z_VALUE + 1);
        $frame->setAlign("left", "top");

        $backgroundQuad = new Quad();
        $frame->addChild($backgroundQuad);
        $backgroundQuad->setSize(30, 6);
        $backgroundQuad->setStyles($this->backStyle, $this->backSubStyle);
        $backgroundQuad->setPosition($this->minimizedX, $this->minimizedY);
        $backgroundQuad->setAlign("left", "top");
        $backgroundQuad->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 1);


        $titleLabel = new Label();
        $frame->addChild($titleLabel);
        if ($new)
            $titleLabel->setText('$o$0f0Veto              ');
        else
            $titleLabel->setText('$o$fffVeto              ');

        $titleLabel->setTextSize(1);
        $titleLabel->setPosition($this->minimizedX + 2.5, $this->minimizedY - 1.5);
        $titleLabel->setAlign("left", "top");
        $titleLabel->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 2);
        $titleLabel->setAction(self::ACT_VETO_MAXIMISE);

        if ($login != null)
        {
            $this->maniaControl->getManialinkManager()->sendManialink($manialink, $login);
        }
    }

    /**
     * Count vote for a map
     * @param string $map map UID
     */
    protected function countVoteMap($map)
    {
        $nb = 0;
        foreach ($this->currentVoteVeto as $player => $votemap)
        {
            if ($votemap == $map)
                ++$nb;
        }
        return $nb;
    }

    /**
     * Count vote foreach maps
     * @return array<string,int>
     */
    protected function countVoteMaps()
    {
        $maps = [];
        foreach ($this->currentVoteVeto as $player => $map)
        {
            if (isset($maps[$map]))
                ++$maps[$map];
            else
                $maps[$map] = 1;
        }
        return $maps;
    }

    /**
     * Get max value
     */
    protected function max($a, $b)
    {
        if ($a > $b)
            return $a;
        else
            return $b;
    }

    /**
     * Get the max vote value in the current vetoSquence
     */
    protected function getMaxVoteMap()
    {
        $max = 0;
        $maps = $this->countVoteMaps();

        foreach ($maps as $map)
        {
            $max = $this->max($max, $map);
        }

        return $max;
    }

    /**
     * @param Map map obj
     * @return string link
     */
    public function getThumbnailLink($map)
    {
        foreach ($this->thumbnailMaps as $m)
        {
            if ($m->uid == $map->uid)
                return "https://mximage.yoxclan.fr/lq/{$m->prefix}/{$m->id}.jpg";
        }
        return "https://via.placeholder.com/400x290.jpg";
    }


 
    private function buildTitle($frame, $x, $y)
    {
        $titleLabel = new Label();
        $frame->addChild($titleLabel);
        $titleLabel->setText("Veto");
        $titleLabel->setTextSize(2);
        $titleLabel->setPosition($x + 2, $y - 2);
        $titleLabel->setAlign("left", "top");
        $titleLabel->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 2);
    }

    private function buildProgressBar($frame, $time, $x, $y, $width, $height)
    {
        $timeGauge = new Gauge();
        $timeGauge->setPosition($x, $y - ($height - 10));
        $timeGauge->setStyle("EnergyBar");
        $timeGauge->setDrawBackground(false);
        $timeGauge->setWidth($width - 40);
        $timeGauge->setHeight(8);
        $timeGauge->setRatio($time / (int)$this->chooseTime);
        $timeGauge->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 3);
        $timeGauge->setAlign("left", "top");
        if ($time < 20)
            $timeGauge->setColor("d50");
        if ($time < 10)
            $timeGauge->setColor("f00");

        $frame->addChild($timeGauge);


        $timeLabel = new Label();
        $frame->addChild($timeLabel);
        $timeLabel->setText("($time s)");
        if ($time < 10)
        {
            $timeLabel->setText('$f00' . $timeLabel->getText());
        }
        else
        {
            if ($time < 20)
            {
                $timeLabel->setText('$d50' . $timeLabel->getText());
            }
        }
        $timeLabel->setTextSize(2);
        $timeLabel->setPosition($x + ($width / 2) + 8, $y - 2);
        $timeLabel->setAlign("left", "top");
        $timeLabel->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 2);
    }

    private function buildMinimizeButton($frame, $x, $y, $width)
    {
        $miniLabel = new Label();
        $frame->addChild($miniLabel);
        $miniLabel->setTextSize(1);
        $miniLabel->setPosition(($x + $width) - 2, $y - 2);
        $miniLabel->setAlign("right", "top");
        $miniLabel->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 2);
        $miniLabel->setText('');
        $miniLabel->setAction(self::ACT_VETO_MINIMISE);
    }

    private function buildStringState($frame, $sequenceNode, $x, $y, $width)
    {
        $stateLabel = new Label();
        $frame->addChild($stateLabel);
        $stateLabel->setTextSize(2);
        $stateLabel->setPosition($x + ($width / 2) - 4, $y - 2);
        $stateLabel->setAlign("center", "top");
        $stateLabel->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 2);

        if ($sequenceNode->team == "A")
            $stateLabel->setText("Team A ");
        else
            $stateLabel->setText("Team B ");

        if ($sequenceNode->pick)
            $stateLabel->setText($stateLabel->getText() . '$0f0 Pick');
        else
            $stateLabel->setText($stateLabel->getText() . '$f00 Ban');
    }

    private function buildDebugSequenceState($frame, $x, $y)
    {
        $sequenceLabel = new Label();
        $frame->addChild($sequenceLabel);
        $sequenceLabel->setTextSize(1);
        $sequenceLabel->setPosition($x + 30, $y - 2);
        $sequenceLabel->setAlign("center", "top");
        $sequenceLabel->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 2);
        $sequenceLabel->setText("Sequence ID : " . $this->currentVetoNodeIndex);
    }


    private function buildRandomButton($frame, $sequenceNode, $login, $spec, $x, $y, $width, $height)
    {

        $randomButton = new Label();
        $frame->addChild($randomButton);
        $randomButton->setPosition($x + ($width - 31), $y - $height + 10);
        $randomButton->setSize(4, 8);
        $randomButton->setStyle("CardButtonMediumS");
        $randomButton->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 4);
        $randomButton->setAlign("left", "right");
        if ($sequenceNode->pick)
            $randomButton->setText("Random Pick");
        else
            $randomButton->setText("Random Ban");

        if ($this->chooseVote)
        {
            $nb = $this->countVoteMap("RANDOM");
            if ($nb > 0)
                $randomButton->setText($randomButton->getText() . " ($nb)");

            if ($login != null && isset($this->currentVoteVeto[$login]) && $this->currentVoteVeto[$login] == "RANDOM")
            {
                $randomButton->setText('$ccc' . $randomButton->getText());
            }
        }
        if ($spec)
            $randomButton->setTextPrefix('$777');
        else
            $randomButton->setAction(self::ACT_VETOLIST_SELECT . "RANDOM");
    }


    private function buildVetoState($frame, $x, $y)
    {
        if(!($this->showState))
            return;

        $curY = $y;
        for($i = 0; $i < count($this->vetoSequence); ++$i)
        {
           
            $stateQuad = new Quad();
            $frame->addChild($stateQuad);

            $stateQuad->setPosition($x,$curY, ManialinkManager::MAIN_MANIALINK_Z_VALUE + 2);
            $stateQuad->setAlign("left", "top");
            $stateQuad->setSize(20, 6);
            $stateQuad->setStyles("Bgs1", "BgCard");
            if($i < $this->currentVetoNodeIndex)
            {
                $stateQuad->setBackgroundColor("000");
                $stateQuad->setColorize("000");
            }
            else
            {
                if($this->vetoSequence[$i]->pick)
                {
                    $stateQuad->setColorize("0f0");
                    $stateQuad->setBackgroundColor("0f0");
                }
                else
                {
                    $stateQuad->setColorize("f00");
                    $stateQuad->setBackgroundColor("f00");
                }

            }

            $stateLabel = new Label();
            $frame->addChild($stateLabel);
            $stateLabel->setAlign("left", "top");
            $stateLabel->setPosition($x+2,$curY-1.5, ManialinkManager::MAIN_MANIALINK_Z_VALUE + 3);
            if($this->vetoSequence[$i]->team == "A")
                $stateLabel->setText('$09e');
            else
                $stateLabel->setText('$f80');
            
            $stateLabel->setText($stateLabel->getText() . "Team " . $this->vetoSequence[$i]->team);
            $stateLabel->setTextSize(2);

            if($i == $this->currentVetoNodeIndex)
            {
                $arrow = new Quad();
                $frame->addChild($arrow);
                $arrow->setPosition($x + 17.5,$curY-0.5, ManialinkManager::MAIN_MANIALINK_Z_VALUE + 4);
                $arrow->setAlign("left", "top");
                $arrow->setSize(8, 4.5);
                $arrow->setStyles("Icons128x128_1", "Back");
            }


            $curY -= 6.1;
        }



    }


    protected function buildPreloadImages($player)
    {
        $manialink = new ManiaLink(self::ML_PRELOAD);
        $frame = new Frame();
        $manialink->addChild($frame);
        $frame->setVisible(false);


       // $maps = $this->maniaControl->getMapManager()->getMaps();
        foreach($this->maps as $map)
        {
            $thumbNail = new Quad();
            $frame->addChild($thumbNail);
            $thumbNail->setVisible(false);

            $link = $this->getThumbnailLink($map);
            $thumbNail->setImageUrl($link);
            $thumbNail->setImageFocusUrl($link);
        }
        $this->maniaControl->getManialinkManager()->sendManialink($manialink, $player);
    }



    public function buildThumbnailGridManialink($sequenceNode, $login, $spec = false, $time = 999)
    {
        $manialink = new ManiaLink(self::ML_THUMNAILSGRID_ID);
        $frame = new Frame();
        $manialink->addChild($frame);
       
        $frame->setPosition(0, 0, ManialinkManager::MAIN_MANIALINK_Z_VALUE + 1);
        $frame->setAlign("left", "top");

        $this->buildVetoState($frame, $this->thumbGrid_X + $this->thumbGrid_Width, $this->thumbGrid_Y);

        //background
        $backgroundQuad = new Quad();
        $frame->addChild($backgroundQuad);
        $backgroundQuad->setSize($this->thumbGrid_Width, $this->thumbGrid_Height);
        $backgroundQuad->setStyles($this->backStyle, $this->backSubStyle);
        $backgroundQuad->setPosition($this->thumbGrid_X, $this->thumbGrid_Y);  //-80, 70
        $backgroundQuad->setAlign("left", "top");
        $backgroundQuad->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 1);

        //minimize button
        $this->buildMinimizeButton($frame, $this->thumbGrid_X, $this->thumbGrid_Y, $this->thumbGrid_Width);

        //title
        $this->buildTitle($frame, $this->thumbGrid_X, $this->thumbGrid_Y);

        //debug sequence state
        if ($this->isDebug)
            $this->buildDebugSequenceState($frame, $this->thumbGrid_X, $this->thumbGrid_Y);


        //veto state
        $this->buildStringState($frame, $sequenceNode, $this->thumbGrid_X, $this->thumbGrid_Y, $this->thumbGrid_Width);


        if ($this->chooseVote)
            $this->buildProgressBar($frame, $time, $this->thumbGrid_X, $this->thumbGrid_Y, $this->thumbGrid_Width, $this->thumbGrid_Height);



        //display
        //$maps = $this->maniaControl->getMapManager()->getMaps();
        $maps = $this->getAvailableMaps();
        $nbMaps = count($maps);

        $xPos = $this->thumbGrid_X + $this->thumbGrid_Offset;
        $yPos = $this->thumbGrid_Y - $this->thumbGrid_Offset - 5;
        for ($i = 0; $i < $nbMaps; ++$i)
        {
            //back=======================================================================
            $tmpBack = new Quad();
            $frame->addChild($tmpBack);
            $tmpBack->setPosition($xPos, $yPos);
            $tmpBack->setSize($this->thumbGrid_ImageWidth - ($this->thumbGrid_ImageWidth / 2), $this->thumbGrid_ImageHeight  - ($this->thumbGrid_ImageHeight / 2));
            $tmpBack->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 3);
            $tmpBack->setAlign("left", "top");
            $tmpBack->setStyles("Bgs1", "BgIconBorder");



            //thumbnail =================================================================
            $thumbNail = new Quad();
            $frame->addChild($thumbNail);
            $thumbNail->setPosition($xPos + 2, $yPos - 2);
            $thumbNail->setSize($this->thumbGrid_ImageWidth - ($this->thumbGrid_ImageWidth / 2) - 4, $this->thumbGrid_ImageHeight  - ($this->thumbGrid_ImageHeight / 2) - 10);
            $thumbNail->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 4);
            $thumbNail->setAlign("left", "top");
            $thumbNail->setBackgroundColor('f00');
            $link = $this->getThumbnailLink($maps[$i]);
            $thumbNail->setImageUrl($link);
            $thumbNail->setImageFocusUrl($link);

            if (key_exists($maps[$i]->uid, $this->vetoList))
            {
                $pobLabel = new Label();
                $frame->addChild($pobLabel);
                $pobLabel->setTextSize(2);
                $pobLabel->setAlign("left", "top");
                $pobLabel->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 6);
                $pobLabel->setTextEmboss(true);
                if ($this->vetoList[$maps[$i]->uid]["type"] == "ban")
                {
                    $tmpBack->setColorize('f00');
                    $tmpBack->setBackgroundColor('f00');
                    $pobLabel->setPosition($xPos + (($this->thumbGrid_ImageWidth / 4) - 8), $yPos - (($this->thumbGrid_ImageHeight / 4)) + 4);
                    $pobLabel->setText('$f33Team ' . $this->vetoList[$maps[$i]->uid]["team"] . " ✖");
                }
                else
                {
                    $tmpBack->setColorize('0f0');
                    $tmpBack->setBackgroundColor('0f0');
                    $pobLabel->setPosition($xPos + (($this->thumbGrid_ImageWidth / 4) - 10), $yPos - (($this->thumbGrid_ImageHeight / 4)) + 4);
                    $pobLabel->setText('$3f3Team ' . $this->vetoList[$maps[$i]->uid]["team"] . " ✔ " . $this->getPickedOrder($maps[$i]->uid));
                }
            }
            else
            {

                if ($login != null && isset($this->currentVoteVeto[$login]) && $this->currentVoteVeto[$login] == $maps[$i]->uid)
                {
                    $tmpBack->setColorize('0af');
                    $tmpBack->setBackgroundColor('0af');
                }
                if ($spec)
                {
                    $tmpBack->setColorize('222');
                    $tmpBack->setBackgroundColor('222');
                }
                else
                {
                    $tmpBack->setAction(self::ACT_VETOLIST_SELECT . $maps[$i]->uid);
                }
            }


            //MapName =================================================================
            $mapName = new Label();
            $frame->addChild($mapName);
            $mapName->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 5);
            $mapName->setTextSize(2);
            $mapName->setPosition($xPos + 1, $yPos - (($this->thumbGrid_ImageHeight / 2) - 4));
            $mapName->setText($maps[$i]->name);
            $mapName->setAlign("left", "center");
            $nb = $this->countVoteMap($maps[$i]->uid);
            if ($nb > 0)
                $mapName->setText($mapName->getText() . '  $z$fff' . "($nb)");


            //update pos
            $xPos += ($this->thumbGrid_ImageWidth / 2) + $this->thumbGrid_Offset;
            if ($xPos > $this->thumbGrid_X + $this->thumbGrid_Width - ($this->thumbGrid_ImageWidth / 2))
            {
                $xPos = $this->thumbGrid_X + $this->thumbGrid_Offset;
                $yPos -= ($this->thumbGrid_ImageHeight / 2) + $this->thumbGrid_Offset;
            }
        }


        //Random Button
        $this->buildRandomButton($frame, $sequenceNode, $login, $spec, $this->thumbGrid_X, $this->thumbGrid_Y, $this->thumbGrid_Width, $this->thumbGrid_Height);

        if ($login == null)
        {
            $this->maniaControl->getChat()->sendErrorToAdmins("Unable to send a manialink to an invalid/null login");
            return;
        }

        $this->maniaControl->getManialinkManager()->sendManialink($manialink, $login);
    }


    /**
     * @param VetoSequenceNode $sequence
     * @param string|array|null $login
     */
    public function buildListManialink($sequenceNode, $login, $spec = false, $time = 999)
    {

        $manialink = new ManiaLink(self::ML_VETOLIST_ID);

        $frame = new Frame();
        $manialink->addChild($frame);
        $frame->setPosition(0, 0, ManialinkManager::MAIN_MANIALINK_Z_VALUE + 1);
        $frame->setAlign("left", "top");

        $this->buildVetoState($frame, $this->listX + $this->listWidth, $this->listY);

        $backgroundQuad = new Quad();
        $frame->addChild($backgroundQuad);
        $backgroundQuad->setSize($this->listWidth, $this->listHeight);
        $backgroundQuad->setStyles($this->backStyle, $this->backSubStyle);
        $backgroundQuad->setPosition($this->listX, $this->listY);  //-80, 70
        $backgroundQuad->setAlign("left", "top");
        $backgroundQuad->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 1);

        $this->buildMinimizeButton($frame, $this->listX, $this->listY, $this->listWidth);
        $this->buildTitle($frame, $this->listX, $this->listY);

        if ($this->isDebug)
            $this->buildDebugSequenceState($frame, $this->listX, $this->listY);


        $this->buildStringState($frame, $sequenceNode, $this->listX, $this->listY, $this->listWidth);



        $stateLabel = new Label();
        $frame->addChild($stateLabel);
        $stateLabel->setTextSize(1);
        $stateLabel->setPosition($this->listX + ($this->listWidth / 2) - 4, $this->listY - 2);
        $stateLabel->setAlign("center", "top");
        $stateLabel->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 2);


        if ($this->chooseVote)
            $this->buildProgressBar($frame, $time, $this->listX, $this->listY, $this->listWidth, $this->listHeight);



       // $maps = $this->maniaControl->getMapManager()->getMaps();
        $maps = $this->getAvailableMaps();
        $nbMaps = count($maps);
        for ($i = 0; $i < $nbMaps; ++$i)
        {
            $tmpBack = new Quad();
            $tmpBack->setPosition($this->listX + 2, ($this->listY - 6) - ($i * 10));
            $tmpBack->setSize($this->listWidth - 5, 10);
            $tmpBack->setStyles("Bgs1", "BgCardOnline");
            $tmpBack->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 3);
            $tmpBack->setAlign("left", "top");
            $tmpBack->setBackgroundColor('$f00');
            $frame->addChild($tmpBack);

            $tmpLabel = new Label();
            $tmpLabel->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 4);
            $tmpLabel->setPosition($this->listX + 4, ($this->listY - 10) - ($i * 10));
            $tmpLabel->setText($maps[$i]->name);
            $tmpLabel->setAlign("left", "center");
            $frame->addChild($tmpLabel);

            $tmpButton = new Label();
            $tmpButton->setPosition($this->listX + ($this->listWidth - 33), ($this->listY - 7) - ($i * 10));
            $tmpButton->setSize(30, 8);
            $tmpButton->setAlign("left", "right");
            $tmpButton->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 4);

            if (key_exists($maps[$i]->uid, $this->vetoList))
            {
                $tmpButton->setPosition($this->listX + ($this->listWidth - 30), ($this->listY - 9) - ($i * 10));
                if ($this->vetoList[$maps[$i]->uid]["type"] == "ban")
                {
                    $tmpBack->setStyles("Bgs1", "BgCardChallenge");
                    $tmpBack->setColorize("f56");
                    $tmpButton->setText('$f00Team ' . $this->vetoList[$maps[$i]->uid]["team"] . " ✖");
                }
                else
                {
                    $tmpBack->setStyles("Bgs1", "BgCardZone");
                    $tmpBack->setColorize("5f6");
                    $tmpButton->setText('$0f0Team ' . $this->vetoList[$maps[$i]->uid]["team"] . " ✔ " . $this->getPickedOrder($maps[$i]->uid));
                }
            }
            else
            {
                $tmpButton->setStyle("CardButtonMediumS");

                if ($sequenceNode->pick)
                    $tmpButton->setText("Pick");
                else
                    $tmpButton->setText("Ban");

                if ($this->chooseVote)
                {
                    $nb = $this->countVoteMap($maps[$i]->uid);
                    if ($nb > 0)
                        $tmpButton->setText($tmpButton->getText() . " ($nb)");

                    if ($login != null && isset($this->currentVoteVeto[$login]) && $this->currentVoteVeto[$login] == $maps[$i]->uid)
                    {
                        $tmpButton->setText('$ccc' . $tmpButton->getText());
                    }
                }


                if ($spec)
                    $tmpButton->setTextPrefix('$777');
                else
                    $tmpButton->setAction(self::ACT_VETOLIST_SELECT . $maps[$i]->uid);
            }
            $frame->addChild($tmpButton);
        }


        //Random Button
        $this->buildRandomButton($frame, $sequenceNode, $login, $spec, $this->listX, $this->listY, $this->listWidth, $this->listHeight);


        if ($login == null)
        {
            $this->maniaControl->getChat()->sendErrorToAdmins("Unable to send a manialink to an invalid/null login");
            return;
        }

        $this->maniaControl->getManialinkManager()->sendManialink($manialink, $login);
    }

    public function showIcon($login = false)
    {
        if (!$this->startWithVote)
            return;


        $pos               = $this->maniaControl->getManialinkManager()->getSidebarMenuManager()->getEntryPosition(self::ICON_MENU);
        $width             = $this->maniaControl->getSettingManager()->getSettingValue($this->maniaControl->getManialinkManager()->getSidebarMenuManager(), SidebarMenuManager::SETTING_MENU_ITEMSIZE);
        $quadStyle         = $this->maniaControl->getManialinkManager()->getStyleManager()->getDefaultQuadStyle();
        $quadSubstyle      = $this->maniaControl->getManialinkManager()->getStyleManager()->getDefaultQuadSubstyle();
        $itemMarginFactorX = 1.3;
        $itemMarginFactorY = 1.2;

        $itemSize = $width;

        $maniaLink = new ManiaLink(self::MLID_ICON);

        //Custom Vote Menu Iconsframe
        $frame = new Frame();
        $maniaLink->addChild($frame);
        $frame->setPosition($pos->getX(), $pos->getY());
        $frame->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE);

        $backgroundQuad = new Quad();
        $frame->addChild($backgroundQuad);
        $backgroundQuad->setSize($width * $itemMarginFactorX, $itemSize * $itemMarginFactorY);
        $backgroundQuad->setStyles($quadStyle, $quadSubstyle);

        $iconFrame = new Frame();
        $frame->addChild($iconFrame);

        $iconFrame->setSize($itemSize, $itemSize);
        $itemQuad = new Quad_Icons64x64_1();
        $itemQuad->setSubStyle($itemQuad::SUBSTYLE_RestartRace);
        $itemQuad->setSize($itemSize, $itemSize);
        $iconFrame->addChild($itemQuad);
        $itemQuad->setAction(self::ACT_VETO_START);

        // Send manialink
        $this->maniaControl->getManialinkManager()->sendManialink($maniaLink, $login);
    }



    //=================================================================================================================================================================
    //==[Handle ManiaLink]=============================================================================================================================================
    //=================================================================================================================================================================

    public function handleVetoMaximise(array $callback, Player $player)
    {
        if ($player == null || is_string($player))
        {
            $this->maniaControl->getChat()->sendErrorToAdmins("<handleVetoMaximise> invalid player type or null !");
            return;
        }
        $this->maniaControl->getManialinkManager()->hideManialink(self::ML_VETOLIST_ID, $player);
        $this->maniaControl->getManialinkManager()->hideManialink(self::ML_VETOMINIMIZE_ID, $player);
        $this->windowStateByPlayer[$player->login] = true;
        if (isset($this->newSequenceNotified[$player->login]))
        {
            $this->newSequenceNotified[$player->login] = false;
        }
        $this->showManialink($this->vetoSequence[$this->currentVetoNodeIndex], $player);
    }

    public function handleVetoMinimize(array $callback, Player $player)
    {
        if ($player == null || is_string($player))
        {
            $this->maniaControl->getChat()->sendErrorToAdmins("<handleVetoMaximise> invalid player type or null !");
            return;
        }


        $this->maniaControl->getManialinkManager()->hideManialink(self::ML_VETOLIST_ID, $player);
        $this->maniaControl->getManialinkManager()->hideManialink(self::ML_VETOMINIMIZE_ID, $player);
        $this->maniaControl->getManialinkManager()->hideManialink(self::ML_THUMNAILSGRID_ID, $player);
        $this->windowStateByPlayer[$player->login] = false;
        $this->showManialink($this->vetoSequence[$this->currentVetoNodeIndex], $player, false);
    }

    public function handleVetoStart(array $callback, Player $player)
    {
        $this->startVeto($this->vetoString, $player);
    }

    public function handleManialinkPageAnswer(array $callback)
    {
        $actionId       = $callback[1][2];
        $boolSelectMap = (strpos($actionId, self::ACT_VETOLIST_SELECT) === 0);
        if (!$boolSelectMap)
            return;


        $login  = $callback[1][1];
        $player = $this->maniaControl->getPlayerManager()->getPlayer($login);
        if ($player)
        {
            $actionArray = explode('.', $callback[1][2]);
            $map = $actionArray[2];
            if ($this->chooseVote)
            {
                if (isset($this->currentVoteVeto[$login]) && $this->currentVoteVeto[$login] == $map)
                    unset($this->currentVoteVeto[$login]);
                else
                    $this->currentVoteVeto[$login] = $map;
            }
            else
            {
                $this->executeAction($player, $map);
            }
        }
    }

    //=================================================================================================================================================================
    //==[Handle Callback]==============================================================================================================================================
    //=================================================================================================================================================================

    public function handlePlayerInfosChanged(Player $player)
    {
        if($this->vetoStarted)
        {
            $this->maniaControl->getManialinkManager()->hideManialink(self::ML_THUMNAILSGRID_ID, $player);
            $this->maniaControl->getManialinkManager()->hideManialink(self::ML_VETOLIST_ID, $player);
            $this->maniaControl->getManialinkManager()->hideManialink(self::ML_VETOMINIMIZE_ID, $player);
            $this->showManialink($this->vetoSequence[$this->currentVetoNodeIndex], $player);
        }

    }

    
    public function handlePlayerConnect(Player $player)
    {
        $this->maniaControl->getManialinkManager()->hideManialink(self::ML_PRELOAD, $player);
        $this->buildPreloadImages($player);
        $this->showIcon($player->login);
        if($this->vetoStarted)
            $this->showManialink($this->vetoSequence[$this->currentVetoNodeIndex], $player);
    }



    //=================================================================================================================================================================
    //==[Remote Plugin Callback]=======================================================================================================================================
    //=================================================================================================================================================================

    public function registerOnVetoFinishedCallBack(Plugin $listener, $method)
    {
        $this->onVetoFinishedListeners[] = [$listener, $method];
    }

    protected function triggerOnVetoFinished($json)
    {
        foreach ($this->onVetoFinishedListeners as $callback)
        {
            call_user_func_array($callback, [$json]);
        }
    }

    public function registerCheckMasterPluginAllowToStart(Plugin $listener, $method)
    {
        $this->checkMasterPluginStart[] = [$listener, $method];
    }


    /**
     * @return bool Return true if master plugins allow the veto to start
     */
    protected function checkMasterPluginAllowedTostart() //TODO: return the Plugin who refused and his error message
    {
        foreach ($this->checkMasterPluginStart as $callback)
        {
            if (!(call_user_func_array($callback, [])))
                return false;
        }
        return true;
    }

    public function registerCustomVoteVetoStart(Plugin $listener, $method)
    {
        //Unique callback
        $this->customVoteVetoStart = [$listener, $method];
    }




    /**
     * @see \ManiaControl\Plugins\Plugin::unload()
     */
    public function unload()
    {
        $this->maniaControl = null;
    }

    /**
     * @see \ManiaControl\Plugins\Plugin::getId()
     */
    public static function getId()
    {
        return self::ID;
    }

    /**
     * @see \ManiaControl\Plugins\Plugin::getName()
     */
    public static function getName()
    {
        return self::NAME;
    }

    /**
     * @see \ManiaControl\Plugins\Plugin::getVersion()
     */
    public static function getVersion()
    {
        return self::VERSION;
    }

    /**
     * @see \ManiaControl\Plugins\Plugin::getAuthor()
     */
    public static function getAuthor()
    {
        return self::AUTHOR;
    }

    /**
     * @see \ManiaControl\Plugins\Plugin::getDescription()
     */
    public static function getDescription()
    {
        return 'Veto manager, can be connected to other plugins';
    }

    /**
     * @see \ManiaControl\Plugins\Plugin::prepare()
     */
    public static function prepare(ManiaControl $maniaControl)
    {
        // TODO: Implement prepare() method.
    }
}
