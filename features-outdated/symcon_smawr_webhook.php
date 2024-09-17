<?php
/*
Webhook script to catch data from smaemd with symcon feature
see https://github.com/datenschuft/SMA-EM

2018-12-30 Tommi2Day
*/

//smaemd symcon config sample:
/*
[SMA-EM]
# serials of sma-ems the daemon should take notice
# seperated by space
# MUST SET EXACT
serials=30028xxxx
# features could filter serials to, but wouldn't see serials if these serials was not defines in SMA-EM serials
# list of features to load/run
features=pvdata symcon

[DAEMON]
pidfile=/run/smaemd.pid
# listen on an interface with the given ip
# use 0.0.0.0 for any interface
ipbind=0.0.0.0
# multicast ip and port of sma-datagrams
# defaults
mcastgrp=239.12.255.254
mcastport=9522
statusdir=

[FEATURE-symcon]
# change for your environment
host=ips
port=3777
emhook=/hook/smaem
pvhook=/hook/smawr
timeout=5

#authorisation must match $hook_user and $hook_password
user=Symcon
password=SMA-EMdata

#fields should match $vartypes config
fields=pconsume,psupply,p1consume,p2consume,p3consume,p1supply,p2supply,p3supply,psupplycounter,pconsumecounter
pvfields=AC Power,grid frequency,DC input voltage,daily yield,total yield,Power L1,Power L2,Power L3,Status

# How frequently to send update in sec (defaults to 20 sec)
min_update=30
#extended output usefull only if run not as daemon
debug=0
*/

/*
symcon installation:
-create a new script object with this file content
--add in WebHook Control a hook /hook/smawr  (the smaemd config pvhook name) pointing to this script object
-start smemd with the config file content above
*/

//authorization
$hook_user='Symcon';
$hook_password='SMA-EMdata';

//config
$cat='SMA';
$prefix='SMA-WR';
$autocreate=true;
$test='{"AC Power": 778, "grid frequency": 49.98, ", "DC input voltage": 494.33, "daily yield": 1532, "total yield": 1057298, "Power L1": 260, "Power L2": 262, "Power L3": 261, "Status": "OK", "timestamp": 1546168308.956411, "sender": "raspberry", "serial": "30512xxxx"}';
$vartypes=array(  'serial'=>array('type'=>3,'profile'=>''),
                'Status'=>array('type'=>3,'profile'=>''),
                'AC Power'=>array('type'=>2,'profile'=>'~Watt.14490'),
                'DC input voltage'=>array('type'=>2,'profile'=>'~Volt'),
                'Power L1'=>array('type'=>2,'profile'=>'~Watt.14490'),
                'Power L2'=>array('type'=>2,'profile'=>'~Watt.14490'),
                'Power L3'=>array('type'=>2,'profile'=>'~Watt.14490'),
                'daily yield'=>array('type'=>2,'profile'=>'~Electricity'),
                'total yield'=>array('type'=>2,'profile'=>'~Electricity'),
                'grid frequency'=>array('type'=>2,'profile'=>'~Hertz.50'),
            'timestamp'=>array('type'=>1,'profile'=>'~UnixTimestamp')
);

//auth only if called by webhook
//IPS_LogMessage('SMA-WR WebHook Server',print_r($_SERVER,true));
if (($_IPS['SENDER']=='WebHook') && $hook_user && $hook_password){
    if(!isset($_SERVER['PHP_AUTH_USER']))
        $_SERVER['PHP_AUTH_USER'] = "";
    if(!isset($_SERVER['PHP_AUTH_PW']))
        $_SERVER['PHP_AUTH_PW'] = "";

    if(($_SERVER['PHP_AUTH_USER'] != $hook_user) || ($_SERVER['PHP_AUTH_PW'] != $hook_password)) {
        header('WWW-Authenticate: Basic Realm="SMA-WR WebHook"');
        header('HTTP/1.0 401 Unauthorized');
        echo "Authorization required";
        return;
    }

    $raw=file_get_contents("php://input");
}else {
    $raw=$test;
}

//sanity

$data=@json_decode($raw);
if (!is_object($data)) {
    IPS_LogMessage('SMA-WR WebHook','json_decode error'.print_r($raw,true));
    return;
}

if (!isset($data->{'serial'})) {
    IPS_LogMessage('SMA-WR WebHook','missing serial field'.print_r($data,true));
    return;
}

$serial=$data->{'serial'};
$varids=get_ips_vars($serial,$vartypes,$cat,$prefix);
if (is_null($varids)) {
    IPS_LogMessage($cat, "cannot get ids for device $serial");
    print($cat. " cannot get ids for device $serial");
    //no vars available, maybe autocreate disabled
    return;
}
$fields=array_keys($vartypes);
foreach ($fields as $f) {
    if (isset($data->{"$f"})) {
        $ident=fix_ident($f);
        SetValue($varids["$ident"]['id'],$data->{"$f"});
    }
}
return;
//-----  end main  ---------------------------------------

/**
* function fix_ident
* remove unwanted chars from name for ips_setIdent
* @param string $name
* @returns string
*/
function fix_ident($name) {
    $chars=array(" ","_","-","%");
    $ident=str_replace($chars,"",$name);
    return $ident;
}
/**
 * IPS Variablen handler
 * creates variables as needed
 * returns assoc. Array with IPS Variable ID and Value
 * @param string $serial Device serial
 * @param array $vartypes Array with Variable Names, Types and Profiles
 * @param string $cat Master Categorie Name
 * @param string $prefix default name, will be extended with $addr
 */
function get_ips_vars($serial,$vartypes,$cat,$prefix) {

    $varids=null;
    $master=@IPS_GetCategoryIDByName($cat,0);
    //no master cat, create new
    if (!$master) {
        $master=IPS_CreateCategory();
        IPS_SetName($master,$cat);
        IPS_SetParent($master,0);
        if ($master>0) {
            IPS_LogMessage($cat, "Master category created, ID=$master\n");
        }else{
            IPS_LogMessage($cat, "Can't create Master Category\n");
            return null;
        }
    }

    $id=0;

    if ($master>0) {
        //get chilren devices
        $devices=IPS_GetChildrenIDs($master);
        foreach($devices as $dev) {
            $name=IPS_GetName($dev);

            $vars=IPS_GetChildrenIDs($dev);
            foreach($vars as $vid) {
                $obj=IPS_GetObject($vid);
                $vname=$obj['ObjectIdent'];
                $typ=$obj['ObjectType'];
                if ($typ==2) { //Variable
                    //if ID, here is the address
                    if ($vname="serial") {
                        $i=GetValue($vid);
                        //go out if matches, $id returns the sensor categorie id
                        if ($i===$serial) {
                            $id=$dev;
                            break;
                        }
                    }
                }
            }
            if ($id>0) break;
        }
        if ($id==0) {
            //Sensor with address $addr not found in IPS
            if ($GLOBALS['autocreate']==false) {
                //autocreate disable, ignore new device
                return null;
            }
            //create new sensor
            $id=ips_createCategory();
            ips_setName($id,$prefix.' '.$serial);
            $ident=fix_ident($prefix.$serial);
            ips_setIdent($id,$ident);
            ips_setParent($id,$master);
            //creates all needed variables for the new sensor
            foreach (array_keys($vartypes) as $name) {
                $ident=fix_ident($name);
                $typ=$vartypes["$name"]['type'];
                $profile=$vartypes["$name"]['profile'];
                $vid=IPS_CreateVariable($typ);
                ips_setname($vid,"$name");
                ips_setident($vid,"$ident");
                ips_setParent($vid,$id);
                IPS_SetVariableCustomProfile($vid,$profile);
                //preload variables
                SetValue($vid,0);
                $varids["$ident"]['id']=$vid;
                $varids["$ident"]['val']=0;
                //Store address in $ID for next time
                if ($name=='serial') {
                    SetValue($vid,$serial);
                    $varids["$ident"]['val']=$serial;
                }
            }
        }else{
            //found matching cat, collect ids and vals for this sensor
            $vars=IPS_GetChildrenIDs($id);
            foreach($vars as $vid) {
                $obj=IPS_GetObject($vid);
                $ident=$obj['ObjectIdent'];
                $typ=$obj['ObjectType'];
                if ($typ==2) { //Variable
                    $val=GetValue($vid);
                    $varids["$ident"]['id']=$vid;
                    $varids["$ident"]['val']=$val;
                }

            }

        }
        //returns IDs and Values of this Sensor, Name is Key
        return $varids;
    }
}

/**
 * list existing device categories
 * will be used for deletion
 * @param $catname master category
 * @return array of devices=>id
 */
function list_cats($catname) {
    $master=@IPS_GetCategoryIDByName($catname,0);
    $ret=null;
    if ($master>0) {
        //get chilren sensors
        $devices=IPS_GetChildrenIDs($master);
        foreach($devices as $ids) {
            $name=IPS_GetName($ids);
            $ret{$name}=$ids;
        }
    }
    return $ret;
}
