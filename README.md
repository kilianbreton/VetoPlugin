# VetoManagerPlugin
VetoPlugin For Maniacontrol (TrackMania & ShootMania)
This plugin allows you to manage map vetos and drafts for your competitions matches

## Available Commands
- //startveto      (for admin)
- /startveto       (if non-admin allowed)
- //cancelveto     (for admin)


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
    $this->maniaControl->getPluginManager()->getPlugin(self::VETO_PLUGIN)->registerOnVetoFinishedCallBack($this, "myCallbackMethod");


//....

public function myCallbackMethod($json)
{
    var_dump($json);
    //...
}

```
