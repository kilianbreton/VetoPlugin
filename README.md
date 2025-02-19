# VetoManagerPlugin
VetoPlugin For Maniacontrol (TrackMania & ShootMania)

This plugin allows you to manage map vetos and drafts for your competitions matches

## Available Commands
- //startveto      (for admin)
- /startveto       (if non-admin allowed)
- //cancelveto     (for admin)


## Installation 
- From ManiaControl UI
- Manually by copying files into maniacontrol/plugins/

## Download 
- [From ManiaControl](https://maniacontrol.com/plugins/185)



## Call veto from another plugin
Disable standAlone mode in settings

Add constant to your plugin : 
```php
const VETO_PLUGIN = "Ankou\\VetoManagerPlugin";
```

Call startVeto and cancelVeto methods : 

```php
if($this->maniaControl->getPluginManager()->isPluginActive(self::VETO_PLUGIN))
    $this->maniaControl->getPluginManager()->getPlugin(self::VETO_PLUGIN)->startVeto("-ABBAA+ABX");

if($this->maniaControl->getPluginManager()->isPluginActive(self::VETO_PLUGIN))
    $this->maniaControl->getPluginManager()->getPlugin(self::VETO_PLUGIN)->cancelVeto();
```


Register callback : 

```php
if($this->maniaControl->getPluginManager()->isPluginActive(self::VETO_PLUGIN))
    $this->maniaControl->getPluginManager()->getPlugin(self::VETO_PLUGIN)->registerOnVetoFinishedCallBack($this, "onVetoFinished");

if($this->maniaControl->getPluginManager()->isPluginActive(self::VETO_PLUGIN))
    $this->maniaControl->getPluginManager()->getPlugin(self::VETO_PLUGIN)->registerCheckMasterPluginAllowToStart($this, "allowVeto");



//....

public function onVetoFinished($json)
{
    var_dump($json);
}

public function allowVeto()
{
    if($someting)
       Logger::log("Allowed");
    else
       Logger::log("Not allowed");

   return $something; //(bool)
}

```
