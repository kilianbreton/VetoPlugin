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
use FML\Script\Features\MapInfo;
use ManiaControl\Players\Player;
use ManiaControl\Plugins\Plugin;
use ManiaControl\Utils\Formatter;
use ManiaControl\Settings\Setting;
use FML\Controls\Labels\Label_Text;
use ManiaControl\Callbacks\Callbacks;
use FML\Script\Features\ActionTrigger;
use ManiaControl\Players\PlayerManager;
use ManiaControl\Files\AsyncHttpRequest;
use FML\Controls\Quads\Quad_Icons64x64_1;
use ManiaControl\Settings\SettingManager;
use ManiaControl\Commands\CommandListener;
use ManiaControl\Callbacks\CallbackManager;
use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Admin\AuthenticationManager;
use ManiaControl\Manialinks\ManialinkManager;
use ManiaControl\Configurator\GameModeSettings;
use ManiaControl\Communication\CommunicationAnswer;
use ManiaControl\Communication\CommunicationListener;
use ManiaControl\Callbacks\TimerListener; // for pause
use ManiaControl\Manialinks\ManialinkPageAnswerListener;
use Maniaplanet\DedicatedServer\InvalidArgumentException;
use ManiaControl\Callbacks\Structures\ShootMania\OnShootStructure;
use ManiaControl\Callbacks\Structures\TrackMania\OnScoresStructure;
use ManiaControl\Callbacks\Structures\Common\StatusCallbackStructure;


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
 * @version 0.1
 */
class VetoManagerPlugin implements CallbackListener, CommandListener, TimerListener, CommunicationListener, Plugin, ManialinkPageAnswerListener
{
    const ID      = 185;
    const VERSION = 0.1;
    const NAME    = 'VetoManager';
    const AUTHOR  = 'Ankou';

    //Debug
    const __DEBUG__CLICK = false;


    //Settings
    const SETTING_LIST_X = "List pos X";
    const SETTING_LIST_Y = "List pos Y";
    const SETTING_STANDALONE = "Is standAlone";
    const SETTING_VETOSTRING = "Veto string (only for standAlone)";
    protected $isStandAlone = false;
    protected $vetoString = "";
    protected $listX = -80;
    protected $listY = 70;



    //ManiaLink
    const ML_VETOLIST_ID        = "VetoManager.List";
    const ML_VETOMINIMISED_ID   = "VetoManager.Minimised";
    const ACT_VETO_MAXIMISE     = "VetoManager.Maximise";
    const ACT_VETO_MINIMISE     = "VetoManager.Minimise";
    const ACT_VETOLIST_SELECT   = "VetoManager.List.";


    protected $maxNbBan = -1;
    protected $maxNbPick = -1;
    protected $vetoSequence = [];
    protected $currentVetoNodeIndex = -1;
    protected $banList = [];
    protected $pickList = [];
    protected $windowStateByPlayer = [];    //["login" => true/false] (true = maximised)


    /** @var ManiaControl $maniaControl */
    protected $maniaControl = null;



    /**
     * @see \ManiaControl\Plugins\Plugin::load()
     */
    public function load(ManiaControl $maniaControl)
    {
        $this->maniaControl = $maniaControl;

        //ManiaLink callbacks
        $this->maniaControl->getManialinkManager()->registerManialinkPageAnswerListener(self::ACT_VETO_MAXIMISE, $this, 'handleVetoMaximise');
        $this->maniaControl->getManialinkManager()->registerManialinkPageAnswerListener(self::ACT_VETO_MINIMISE, $this, 'handleVetoMinimise');

        //CallBacks
        $this->maniaControl->getCallbackManager()->registerCallbackListener(SettingManager::CB_SETTING_CHANGED, $this, 'updateSettings');
        $this->maniaControl->getCallbackManager()->registerCallbackListener(CallbackManager::CB_MP_PLAYERMANIALINKPAGEANSWER, $this, 'handleManialinkPageAnswer');

        //Commands
        $this->maniaControl->getCommandManager()->registerCommandListener("startveto", $this, "onCommandStartVeto", true, "Start the veto");


        //settings
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_STANDALONE, true);
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_VETOSTRING, "-A-BB-AA-B+B+A+X", "allowed chars : -+ABX (- = Ban; + = Pick; A = Team A; B = Team B; X = Auto last map)");
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_LIST_X, -80);
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_LIST_Y, 70);


        $this->updateSettings();
    }

    public function updateSettings()
    {
        $this->isStandAlone = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_STANDALONE);
        $this->vetoString = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_VETOSTRING);
        $this->listX = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_LIST_X);
        $this->listY = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_LIST_Y);
        if ($this->isStandAlone)
        {
            if (!($this->validateString($this->vetoString, $message)))
            {
                $this->maniaControl->getChat()->sendErrorToAdmins("Invalid veto string '{$this->vetoString}' ($message)");
            }
        }
    }



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


    public function setMapQueue()
    {
        if(count($this->pickList) > 0)
        {
            $this->maniaControl->getMapManager()->getMapQueue()->clearMapQueue();
            $start = 0;
            if($this->maniaControl->getMapManager()->getCurrentMap()->uid == $this->pickList[0])
            {
                $start = 1;
                $this->maniaControl->getClient()->restartMap();
            }
            for($i = $start; $i < count($this->pickList); ++$i)
            {
                $this->maniaControl->getMapManager()->getMapQueue()->serverAddMapToMapQueue($this->pickList[$i]);
            }
           
        }
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
        var_dump($callback);
    }


    protected function executeAction($player, $map)
    {
        if ($this->vetoSequence[$this->currentVetoNodeIndex]->pick)
        {
            echo "Pick : $map \n";
            $this->pickList[] = $map;
        }
        else
        {
            echo "Ban : $map \n";
            $this->banList[] = $map;
        }
        $this->maniaControl->getManialinkManager()->hideManialink(self::ML_VETOLIST_ID);
        $this->maniaControl->getManialinkManager()->hideManialink(self::ML_VETOMINIMISED_ID);
        if (++$this->currentVetoNodeIndex < count($this->vetoSequence))
        {
            $this->showManialink($this->vetoSequence[$this->currentVetoNodeIndex]);
        }
        else
        {
            $this->setMapQueue();
        }
    }


    /**
     * @return bool True if string ok (+update the veto sequence if string is good)
     */
    protected function validateString($string, &$message)
    {
        if (strlen($string) == 0)
            return false;

     //   echo "Validate veto string ... \n";
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
                        $message =  "Cannot start ban sequence (bad state)\n";
                        return false;
                    }

                    $state = StringParserState::INBAN;
                    break;

                case "+":
                    if ($state == StringParserState::NULL)
                    {
                        $message =  "Cannot start pick sequence (bad state)\n";
                        return false;
                    }
                    $state = StringParserState::INPICK;
                    break;

                case "A":
                case "B":
                    switch ($state)
                    {
                        case StringParserState::NULL:
                            $message =  "Cannot select team, without start sequence\n";
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
                        $message =  "X should be used in pick sequence\n";
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
            $message =  "Auto map can't be used, bad maplist number\n";
            return false;
        }

        $this->vetoSequence = $tempSequence;
        $this->maxNbBan = $strNbBan;
        $this->maxNbPick = $strNbPick;
        $this->banList = [];
        $this->pickList = [];

        return true;
    }

    public function onCommandStartVeto(array $chatCallback, Player $player)
    {
        if (count($this->vetoSequence) > 0)
        {
            $this->currentVetoNodeIndex = 0;
            $this->windowStateByPlayer = [];    //reset window state
            $this->pickList = [];
            $this->banList = [];
            $this->showManialink($this->vetoSequence[$this->currentVetoNodeIndex]);
        }
    }


    ///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    /// InterPlugin Communication ////////////////////////////////////////////////////////////////////////////////////////////////////////////
    /////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    public function remoteStartVeto($vetoString)
    {
        $message = "";
        if (!($this->validateString($vetoString, $message)))
        {
            $this->maniaControl->getChat()->sendErrorToAdmins("Invalid veto string '{$this->vetoString}' ($message)");
        }
        if (count($this->vetoSequence) > 0)
        {
            $this->currentVetoNodeIndex = 0;
            $this->windowStateByPlayer = [];    //reset window state
            $this->pickList = [];
            $this->banList = [];
            $this->showManialink($this->vetoSequence[$this->currentVetoNodeIndex]);
        }
    }

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
        $backgroundQuad->setSize(150, 120);
        $backgroundQuad->setStyles("Bgs1", "BgHealthBar");
        $backgroundQuad->setPosition($this->listX, $this->listY);  //-80, 70
        $backgroundQuad->setAlign("left", "top");
        $backgroundQuad->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 1);

        $miniLabel = new Label();
        $frame->addChild($miniLabel);
        $miniLabel->setTextSize(1);
        $miniLabel->setPosition(($this->listX + 150) - 2, $this->listY - 2);
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
            $tmpButton->setPosition($this->listX + 117, ($this->listY - 7) - ($i * 10));
            $tmpButton->setSize(4, 8);
            $tmpButton->setStyle("CardButtonMediumS");
            $tmpButton->setZ(ManialinkManager::MAIN_MANIALINK_Z_VALUE + 4);
            $tmpButton->setAlign("left", "right");
            if (in_array($maps[$i]->uid, $this->banList) || in_array($maps[$i]->uid, $this->pickList))
            {
                if (in_array($maps[$i]->uid, $this->banList))
                {
                    $tmpButton->setText('$f00Banned');
                }
                else
                {
                    $tmpButton->setText('$0f0Picked');
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



        //$this->maniaControl->getManialinkManager()->sendManialink($manialink, $login);
        if ($login != null)
        {
            $this->maniaControl->getManialinkManager()->sendManialink($manialink, $login);
        }
    }

    //=================================================================================================================================================================
    //==[OnCommands]===============================================================================================================================================================
    //=================================================================================================================================================================




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
