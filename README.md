# VetoPlugin
VetoPlugin For Maniacontrol (TM/SM)

## Available Commands
//startveto
//cancelveto


## Call veto from another plugin
Disable standAlone mode in settings

Add constant to your plugin : 
```php
const VETO_PLUGIN = "Ankou\\VetoManagerPlugin";
```

Call startVeto method : 

```php
if($this->maniaControl->getPluginManager()->isPluginActive(self::VETO_PLUGIN))
    $this->maniaControl->getPluginManager()->getPlugin(self::VETO_PLUGIN)->startVeto("-ABBAA+ABX");

if($this->maniaControl->getPluginManager()->isPluginActive(self::VETO_PLUGIN))
    $this->maniaControl->getPluginManager()->getPlugin(self::VETO_PLUGIN)->cancelVeto();
```
