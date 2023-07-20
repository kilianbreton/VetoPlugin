<?php

namespace Ankou;

use Exception;
use FML\ManiaLink;
use FML\Controls\Quad;
use FML\Controls\Frame;
use FML\Controls\Label;
use \ManiaControl\Logger;
use ManiaControl\Maps\Map;
use ManiaControl\ManiaControl;
use ManiaControl\Players\Player;
use ManiaControl\Plugins\Plugin;
use ManiaControl\Players\PlayerManager;
use FML\Controls\Quads\Quad_Icons64x64_1;
use FML\Controls\Quads\Quad_Icons64x64_2;
use ManiaControl\Settings\SettingManager;
use FML\Controls\Quads\Quad_BgsPlayerCard;
use FML\Controls\Quads\Quad_Icons128x32_1;
use ManiaControl\Callbacks\TimerListener; 
use ManiaControl\Commands\CommandListener;
use ManiaControl\Callbacks\CallbackManager;
use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Manialinks\ManialinkManager;
use ManiaControl\Manialinks\SidebarMenuManager;
use FML\Controls\Quads\Quad_UIConstruction_Buttons;
use FML\ManiaLinks;
use ManiaControl\Communication\CommunicationListener;
use ManiaControl\Manialinks\SidebarMenuEntryListener;
use ManiaControl\Manialinks\ManialinkPageAnswerListener;



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
 * @version 0.2
 */
class VetoManagerPlugin implements CallbackListener, CommandListener, TimerListener, CommunicationListener, Plugin, ManialinkPageAnswerListener, SidebarMenuEntryListener
{
    const ID      = 185;
    const VERSION = 0.3;
    const NAME    = 'VetoManager';
    const AUTHOR  = 'Ankou';

    //Debug
    const __DEBUG__CLICK = false;
    const __DEBUG__COMMAND = false;

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


    protected $isStandAlone = false;
    protected $allowUsers = true;
    protected $enableLogs = false;
    protected $vetoString = "";
    protected $listX = -75;
    protected $listY = 70;
    protected $listWidth = 150;
    protected $listHeight = 120;
    protected $backStyle = "";
    protected $backSubStyle = "";

    //ManiaLink
    const ML_VETOLIST_ID        = "VetoManager.List";
    const ML_VETOMINIMISED_ID   = "VetoManager.Minimised";
    const ACT_VETO_MAXIMISE     = "VetoManager.Maximise";
    const ACT_VETO_MINIMISE     = "VetoManager.Minimise";
    const ACT_VETOLIST_SELECT   = "VetoManager.List.";
    const ACT_VETO_START        = "VetoManager.Start";

    const ICON_MENU             = "VetoManager.MenuIcon";
    const MLID_ICON             = "VetoManager.IconWidgetId";


    protected $maxNbBan = -1;
    protected $maxNbPick = -1;
    protected $vetoSequence = [];
    protected $currentVetoNodeIndex = -1;
    protected $vetoList = [];
    protected $windowStateByPlayer = [];    //["login" => true/false] (true = maximised)
    protected $availableMaps = [];
    protected $vetoStarted = false;
    protected $onVetoFinishedListeners = [];




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
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_LIST_X, -75);
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_LIST_Y, 70);
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_LIST_WIDTH, 150);
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_LIST_HEIGHT, 120);
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_BACKSTYLE, "Bgs1"); 
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_BACKSUBSTYLE, "BgWindow2");

        
        $this->updateSettings();


        //ManiaLink callbacks
        $this->maniaControl->getManialinkManager()->registerManialinkPageAnswerListener(self::ACT_VETO_MAXIMISE, $this, 'handleVetoMaximise');
        $this->maniaControl->getManialinkManager()->registerManialinkPageAnswerListener(self::ACT_VETO_MINIMISE, $this, 'handleVetoMinimise');
        $this->maniaControl->getManialinkManager()->registerManialinkPageAnswerListener(self::ACT_VETO_START, $this, 'handleVetoStart');
        $this->maniaControl->getCallbackManager()->registerCallbackListener(CallbackManager::CB_MP_PLAYERMANIALINKPAGEANSWER, $this, 'handleManialinkPageAnswer');

    

        //CallBacks
        $this->maniaControl->getCallbackManager()->registerCallbackListener(SettingManager::CB_SETTING_CHANGED, $this, 'updateSettings');
		$this->maniaControl->getCallbackManager()->registerCallbackListener(PlayerManager::CB_PLAYERCONNECT, $this, 'handlePlayerConnect');


        //Commands
        $this->maniaControl->getCommandManager()->registerCommandListener("startveto", $this, "onCommandStartVeto", false, "Start the veto");
        $this->maniaControl->getCommandManager()->registerCommandListener("startveto", $this, "onCommandStartVeto", true, "Start the veto");
        $this->maniaControl->getCommandManager()->registerCommandListener("cancelveto", $this, "onCommandCancelVeto", true, "Cancel the veto");
        if(self::__DEBUG__COMMAND)
            $this->maniaControl->getCommandManager()->registerCommandListener("randomveto", $this, "onCommandRandomVeto", true, "Random a veto turn");

        
        
    }

    public function updateSettings()
    {
        $this->isStandAlone = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_STANDALONE);
        $this->allowUsers   = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_ALLOWUSERS);
        $this->enableLogs   = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_ENABLELOGS);
        $this->vetoString   = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_VETOSTRING);
        $this->listX        = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_LIST_X);
        $this->listY        = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_LIST_Y);
        $this->listWidth    = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_LIST_WIDTH);
        $this->listHeight   = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_LIST_HEIGHT);
        $this->backStyle    = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_BACKSTYLE);
        $this->backSubStyle = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_BACKSUBSTYLE);
        

        if ($this->isStandAlone)
        {
            if (!($this->validateString($this->vetoString, $message)))
            {
                $this->maniaControl->getChat()->sendErrorToAdmins("Invalid veto string '{$this->vetoString}' ($message)");
            }
        }

        if($this->allowUsers)
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
    }

    /**
     * Execute a click on list (pick or ban)
     */
    protected function executeAction($player, $map)
    {
        if($map == "RANDOM")
        {
            $val = rand(0, count($this->availableMaps));
            $map = $this->availableMaps[$val];
            var_dump($this->availableMaps);
            echo "\n";
            unset($this->availableMaps[$val]);
            $this->availableMaps = array_values($this->availableMaps);
            var_dump($this->availableMaps);
            echo "\n";

        }

        if ($this->vetoSequence[$this->currentVetoNodeIndex]->pick)
        {
            $this->vetoList[$map] = [
                "team" => $this->vetoSequence[$this->currentVetoNodeIndex]->team,
                "type" => "pick"
            ];
        }
        else
        {
            $this->vetoList[$map] = [
                "team" => $this->vetoSequence[$this->currentVetoNodeIndex]->team,
                "type" => "ban"
            ];
        }
        $this->maniaControl->getManialinkManager()->hideManialink(self::ML_VETOLIST_ID);
        $this->maniaControl->getManialinkManager()->hideManialink(self::ML_VETOMINIMISED_ID);
        if (++$this->currentVetoNodeIndex < count($this->vetoSequence))
        {
            $this->showManialink($this->vetoSequence[$this->currentVetoNodeIndex]);
        }
        else
        {
            $this->vetoStarted = false;
            $resultJson = $this->buildVetoResult();
            if($this->enableLogs)
                $this->logVeto($resultJson);
    
            $this->triggerOnVetoFinished($resultJson);
            $this->setMapQueue();
        }
    }

    protected function buildVetoResult()
    {
        $data = [];
        foreach($this->vetoList as $map => $infos)
        {
            $data[] = (object)[
                "map"   => $map,
                "type"  => $infos["type"],
                "team"  => $infos["team"],
            ];
        }
        return json_encode($data);
    }

    protected function logVeto($json)
    {
        Logger::log($json);
    }

    /**
     * Called by executeAction at the end of the Veto
     */
    public function setMapQueue()
    {
        $pickList = $this->getPickList();
        if(count($pickList) > 0)
        {
            $this->maniaControl->getMapManager()->getMapQueue()->clearMapQueue();
            $start = 0;
            if($this->maniaControl->getMapManager()->getCurrentMap()->uid == $pickList[0])
            {
                $start = 1;
                $this->maniaControl->getClient()->restartMap();
            }
            for($i = $start; $i < count($pickList); ++$i)
            {
                $this->maniaControl->getMapManager()->getMapQueue()->serverAddMapToMapQueue($pickList[$i]);
            }
            if($start == 0)
            {
                $this->maniaControl->getClient()->nextMap();
            }
        }
    }

    /**
     * @return array
     */
    protected function getPickList()
    {
        $result = [];
        foreach($this->vetoList as $map => $data)
        {
            if($data["type"] == "pick")
            {
                $result[] = $map;
            }
        }
        return $result;
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

        $nbMaps = $this->maniaControl->getMapManager()->getMapsCount();
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
                        $message =  "Cannot start ban sequence (bad state)";
                        return false;
                    }

                    $state = StringParserState::INBAN;
                    break;

                case "+":
                    if ($state == StringParserState::NULL)
                    {
                        $message =  "Cannot start pick sequence (bad state)";
                        return false;
                    }
                    $state = StringParserState::INPICK;
                    break;

                case "A":
                case "B":
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
        if($nbMaps < $strNbMaps)
        {
            $message =  "Pick and ban number > mapList";
            return false;
        }

        $this->vetoSequence = $tempSequence;
        $this->maxNbBan = $strNbBan;
        $this->maxNbPick = $strNbPick;
        $this->vetoList = [];
        $this->availableMaps = [];
        foreach($this->maniaControl->getMapManager()->getMaps() as $map)
        {
            $this->availableMaps[] = $map->uid;
        }

        return true;
    }

    //=================================================================================================================================================================
    //==[OnCommands]===============================================================================================================================================================
    //=================================================================================================================================================================

    public function onCommandRandomVeto(array $chatCallback, Player $player)
    {
        if($this->vetoStarted)
            $this->executeAction($player, "RANDOM");
    }

    public function onCommandStartVeto(array $chatCallback, Player $player)
    {
        if($this->isStandAlone)
            $this->startVeto($this->vetoString);
    }

    public function startVeto($vetoString)
    {
        if($this->vetoStarted)
        {
            $this->maniaControl->getChat()->sendErrorToAdmins("Veto already started");
            return;
        }
        $message = "";
        if (!($this->validateString($vetoString, $message)))
        {
            $this->maniaControl->getChat()->sendErrorToAdmins("Invalid veto string '{$this->vetoString}' ($message)");
        }
        if (count($this->vetoSequence) > 0)
        {
            if(self::__DEBUG__CLICK)
                $this->maniaControl->getChat()->sendErrorToAdmins("VetoManager is in Debug mode !!");

            $this->currentVetoNodeIndex = 0;
            $this->windowStateByPlayer = [];    //reset window state
            $this->vetoList = [];
            $this->vetoStarted = true;
            $this->showManialink($this->vetoSequence[$this->currentVetoNodeIndex]);
        }
    }

    public function onCommandCancelVeto(array $chatCallback, Player $player)
    {
        $this->cancelVeto($this->vetoString);
    }

    public function cancelVeto()
    {
        $this->currentVetoNodeIndex = 0;
        $this->vetoStarted = false;
        $this->vetoList = [];
        $this->maniaControl->getManialinkManager()->hideManialink(self::ML_VETOLIST_ID);
        $this->maniaControl->getManialinkManager()->hideManialink(self::ML_VETOMINIMISED_ID);
        $this->windowStateByPlayer = []; //reset window state
    }

    //=================================================================================================================================================================
    //==[ManiaLink]====================================================================================================================================================
    //=================================================================================================================================================================

    public function showManialink($sequenceNode, $forcedPlayer = null, $forcedNew = true)
    {
        if ($forcedPlayer != null)
        {
            $this->showManiaLinkByLogin($sequenceNode,$forcedPlayer, $forcedNew);
        }
        else
        {
            $players = $this->maniaControl->getPlayerManager()->getPlayers();
            foreach ($players as $player)
            {
                $this->showManiaLinkByLogin($sequenceNode,$player, $forcedNew);
            }
        }
    }

    public function showManiaLinkByLogin($sequenceNode, $player = null, $forcedNew = true)
    {
        $cantClic = ($player->isSpectator || ($sequenceNode->team == "A" && $player->teamId != 0) || ($sequenceNode->team == "B" && $player->teamId == 0)) && ! self::__DEBUG__CLICK;
        if (isset($this->windowStateByPlayer[$player->login]))
        {
            if ($this->windowStateByPlayer[$player->login])
            {
                $this->buildListManialink($sequenceNode, $player->login, $cantClic);
            }
            else
            {
                $this->buildMinimisedManialink($player, $forcedNew);
            }
        }
        else
        {
            $this->buildListManialink($sequenceNode, $player->login, $cantClic);
        }
    }

    public function buildMinimisedManialink($login, $new = false)
    {
        $manialink = new ManiaLink(self::ML_VETOMINIMISED_ID);

        $frame = new Frame();
        $manialink->addChild($frame);
        $frame->setPosition(0, 0, ManialinkManager::MAIN_MANIALINK_Z_VALUE + 1);
        $frame->setAlign("left", "top");

        $backgroundQuad = new Quad();
        $frame->addChild($backgroundQuad);
        $backgroundQuad->setSize(30, 6);
        $backgroundQuad->setStyles("Bgs1", "BgButtonBig");
        $backgroundQuad->setPosition(90, -85);
        $backgroundQuad->setAlign("left", "top");
        $backgroundQuad->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 1);


        $titleLabel = new Label();
        $frame->addChild($titleLabel);
        if ($new)
            $titleLabel->setText('$o$0f0Veto            ☐');
        else
            $titleLabel->setText('$o$fffVeto            ☐');

        $titleLabel->setTextSize(1);
        $titleLabel->setPosition(94, -87);
        $titleLabel->setAlign("left", "top");
        $titleLabel->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 2);
        $titleLabel->setAction(self::ACT_VETO_MAXIMISE);

        if ($login != null)
        {
            $this->maniaControl->getManialinkManager()->sendManialink($manialink, $login);
        }
    }

    /**
     * @param VetoSequenceNode $sequence
     * @param string|array|null $login
     */
    public function buildListManialink($sequenceNode, $login, $spec = false)
    {

        $manialink = new ManiaLink(self::ML_VETOLIST_ID);

        $frame = new Frame();
        $manialink->addChild($frame);
        $frame->setPosition(0, 0, ManialinkManager::MAIN_MANIALINK_Z_VALUE + 1);
        $frame->setAlign("left", "top");

        $backgroundQuad = new Quad();
        $frame->addChild($backgroundQuad);
        $backgroundQuad->setSize($this->listWidth, $this->listHeight);
        $backgroundQuad->setStyles($this->backStyle, $this->backSubStyle);
        $backgroundQuad->setPosition($this->listX, $this->listY);  //-80, 70
        $backgroundQuad->setAlign("left", "top");
        $backgroundQuad->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 1);

        $miniLabel = new Label();
        $frame->addChild($miniLabel);
        $miniLabel->setTextSize(1);
        $miniLabel->setPosition(($this->listX + $this->listWidth) - 2, $this->listY - 2);
        $miniLabel->setAlign("right", "top");
        $miniLabel->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 2);
        $miniLabel->setText('$o$w-');
        $miniLabel->setAction(self::ACT_VETO_MINIMISE);

        $titleLabel = new Label();
        $frame->addChild($titleLabel);
        $titleLabel->setText("Veto");
        $titleLabel->setTextSize(1);
        $titleLabel->setPosition($this->listX + 2, $this->listY - 2);
        $titleLabel->setAlign("left", "top");
        $titleLabel->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 2);

        $stateLabel = new Label();
        $frame->addChild($stateLabel);
        $stateLabel->setTextSize(1);
        $stateLabel->setPosition(0, $this->listY - 2);
        $stateLabel->setAlign("center", "top");
        $stateLabel->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 2);
        if ($sequenceNode->team == "A")
        {
            $stateLabel->setText("Team A ");
        }
        else
        {
            $stateLabel->setText("Team B ");
        }
        if ($sequenceNode->pick)
        {
            $stateLabel->setText($stateLabel->getText() . " Pick");
        }
        else
        {
            $stateLabel->setText($stateLabel->getText() . " Ban");
        }

        $maps = $this->maniaControl->getMapManager()->getMaps();
        $nbMaps = count($maps);
        for ($i = 0; $i < $nbMaps; ++$i)
        {
            $tmpBack = new Quad();
            $tmpBack->setPosition($this->listX + 2, ($this->listY - 6) - ($i * 10));
            $tmpBack->setSize(146, 10);
            $tmpBack->setStyles("Bgs1", "BgCardOnline");
            $tmpBack->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 3);
            $tmpBack->setAlign("left", "top");
            $frame->addChild($tmpBack);

            $tmpLabel = new Label();
            $tmpLabel->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 4);
            $tmpLabel->setPosition($this->listX + 4, ($this->listY - 10) - ($i * 10));
            $tmpLabel->setText($maps[$i]->name);
            $tmpLabel->setAlign("left", "center");
            $frame->addChild($tmpLabel);

            $tmpButton = new Label();
            $tmpButton->setPosition($this->listX + ($this->listWidth - 33), ($this->listY - 7) - ($i * 10));
            $tmpButton->setSize(4, 8);
            $tmpButton->setStyle("CardButtonMediumS");
            $tmpButton->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 4);
            $tmpButton->setAlign("left", "right");
            
            if (key_exists($maps[$i]->uid, $this->vetoList))
            {
               // if (key_exists($maps[$i]->uid, $this->banList))
                if ($this->vetoList[$maps[$i]->uid]["type"] == "ban")
                {
                    $tmpButton->setText('$f00Banned by ' . $this->vetoList[$maps[$i]->uid]["team"]);
                }
                else
                {
                    $tmpButton->setText('$0f0Picked by ' . $this->vetoList[$maps[$i]->uid]["team"]);
                }
            }
            else
            {
                if ($sequenceNode->pick)
                {
                    $tmpButton->setText("Pick");
                }
                else
                {
                    $tmpButton->setText("Ban");
                }
                if ($spec)
                {
                    $tmpButton->setTextPrefix('$777');
                }
                else
                {
                    $tmpButton->setAction(self::ACT_VETOLIST_SELECT . $maps[$i]->uid);
                }
            }
            $frame->addChild($tmpButton);
        }


        //Random Button

        $randomButton = new Label();
        $randomButton->setPosition($this->listX + ($this->listWidth - 33), $this->listY - $this->listHeight + 10);
        $randomButton->setSize(4, 8);
        $randomButton->setStyle("CardButtonMediumS");
        $randomButton->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 4);
        $randomButton->setAlign("left", "right");
        if ($sequenceNode->pick)
        {
            $randomButton->setText("Random Pick");
        }
        else
        {
            $randomButton->setText("Random Ban");
        }
        if ($spec)
        {
            $randomButton->setTextPrefix('$777');
        }
        else
        {
            $randomButton->setAction(self::ACT_VETOLIST_SELECT . "RANDOM");
        }
        $frame->addChild($randomButton);
        //$this->maniaControl->getManialinkManager()->sendManialink($manialink, $login);
        if ($login != null)
        {
            $this->maniaControl->getManialinkManager()->sendManialink($manialink, $login);
        }
    }

    public function showIcon($login = false)
    {
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
        $this->maniaControl->getManialinkManager()->hideManialink(self::ML_VETOLIST_ID, $player);
        $this->maniaControl->getManialinkManager()->hideManialink(self::ML_VETOMINIMISED_ID, $player);
        $this->windowStateByPlayer[$player->login] = true;
        $this->showManialink($this->vetoSequence[$this->currentVetoNodeIndex], $player);
    }

    public function handleVetoMinimise(array $callback, Player $player)
    {
        $this->maniaControl->getManialinkManager()->hideManialink(self::ML_VETOLIST_ID, $player);
        $this->maniaControl->getManialinkManager()->hideManialink(self::ML_VETOMINIMISED_ID, $player);
        $this->windowStateByPlayer[$player->login] = false;
        $this->showManialink($this->vetoSequence[$this->currentVetoNodeIndex], $player, false);
    }

    public function handleVetoStart(array $callback, Player $player)
    {
        $this->startVeto($this->vetoString);
    }

    public function handleManialinkPageAnswer(array $callback)
    {
        $actionId       = $callback[1][2];
        $boolSelectMap = (strpos($actionId, self::ACT_VETOLIST_SELECT) === 0);
        if (!$boolSelectMap)
        {
            return;
        }

        $login  = $callback[1][1];
        $player = $this->maniaControl->getPlayerManager()->getPlayer($login);

        if ($player)
        {
            $actionArray = explode('.', $callback[1][2]);
            $this->executeAction($player, $actionArray[2]);
        }
        //var_dump($callback);
       
    }

    //=================================================================================================================================================================
    //==[Handle Callback]==============================================================================================================================================
    //=================================================================================================================================================================


    public function handlePlayerConnect(Player $player) {
		$this->showIcon($player->login);
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
        foreach($this->onVetoFinishedListeners as $callback)
        {
            call_user_func_array($callback, [$json]);
        }
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
