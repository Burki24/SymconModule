<?
include_once (__DIR__ . '/libs/WebHookModule.php');

class SymconJSLive extends WebHookModule {

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID, "JSLive");
    }

    public function Create() {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyBoolean("Debug", false);
        $this->RegisterPropertyString("Password", $this->GenerateRandomPassword());
        $this->RegisterPropertyInteger("WebfrontInstanceID", 0);

        $this->RegisterPropertyString("LocalAddress", "");
        $this->RegisterPropertyString("RemoteAddress", $this->GetConnectAddress());

        $this->RegisterPropertyInteger("LocalDataMode", 1);
        $this->RegisterPropertyInteger("RemoteDataMode", 0);

        $this->RegisterPropertyInteger("LocalRefreshTime", 3);
        $this->RegisterPropertyInteger("RemoteRefreshTime", 10);



        //da das direkte reinladen ja nicht geht!
        $this->LoadConnectAddress();
        $this->SetRandomPassword();
    }

    public function ApplyChanges() {
        //Never delete this line!
        parent::ApplyChanges();
    }


    /**
     * This function will be called by the hook control. Visibility should be protected!
     */
    protected function ProcessHookData() {
        $pos = strpos($_SERVER['SCRIPT_NAME'], "/hook/JSLive/js");

        if ($pos !== false) {
            //get javascript files load from webhook
            $subpath = substr($_SERVER['SCRIPT_NAME'], strlen("/hook/JSLive/"));
            $path = __DIR__ . "/" . $subpath;
            if (!file_exists($path)) {
                echo "404 file not found!";
                return;
            }

            header("Content-Type: text/html");

            //Add caching support
            $etag = md5_file($path);
            header("ETag: " . $etag);
            if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && (trim($_SERVER['HTTP_IF_NONE_MATCH']) == $etag)) {
                http_response_code(304);
                return;
            }

            $compressed = gzencode(file_get_contents($path));
            header("Content-Encoding: gzip");
            header("Content-Length: " . strlen($compressed));
            echo $compressed;
        }else{
            //Daten vom Modul Laden
            $Type = substr($_SERVER['SCRIPT_NAME'], strlen("/hook/JSLive/"));
            if(empty($Type)) $Type = "getContend";

            $queryData = array();
            foreach (explode("&", $_SERVER['QUERY_STRING']) as $item) {
                $pos2 = strpos($item, '=');
                if ($pos2 !== false) {
                    $p_arr = explode("=", $item);
                    if (count($p_arr) > 2) continue;
                    $queryData[strtolower($p_arr[0])] = $p_arr[1];
                }
            }

            if (!key_exists("instance", $queryData)) {
                $this->SendDebug('WebHook', 'INSTANCE NOT SET!', 0);
                return ""; //wenn instance Parameter nicht gefunden
            }

            //password prüfen!
            if(!empty($this->ReadPropertyString("Password"))){
                $password = "";
                if (key_exists("pw", $queryData)) {
                    $password = $queryData["pw"];
                }
                if($this->ReadPropertyString("Password") != $password){
                    $this->SendDebug("WebHook", "WRONG PASSWORD!", 0);
                    echo "";
                    return;
                }
            }


            //$this->SendDebug('WebHook', 'INSTANCE:'. $queryData["instance"], 0);
            //$this->SendDebug('WebHook', 'Array QUERY_STRING: ' . print_r($queryData, true), 0);
            //$this->SendDebug('WebHook', 'Array Server: ' . print_r($_SERVER, true), 0);

            header("Content-Type: text/html");


            $sendData = array("cmd" => $Type, "instance" => $queryData["instance"], "queryData" => $queryData);
            $contend = $this->SendDataToChildren(json_encode([
                'DataID' => "{79D59629-E9C5-44F1-0F34-0FBC5C88F307}",
                'Buffer' => utf8_encode(json_encode($sendData))
            ]));

            if (count($contend) == 0){
                $this->SendDebug("WebHook", "NO INSTANCE FOUND!", 0);
                return ""; //wenn instance nicht gefunden
            }

            if($Type = "getContend") {
                //check if local
                $isLocal = $this->CheckIfLocal($_SERVER["HTTP_HOST"]);
                $contend = $this->ReplacePlaceholder($contend[0], $isLocal, $queryData["instance"]);
            }

            //Add caching support
            $etag = md5($contend);
            header("ETag: " . $etag);
            if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && (trim($_SERVER['HTTP_IF_NONE_MATCH']) == $etag)) {
                http_response_code(304);
                return;
            }
            if($this->ReadPropertyBoolean("Debug")) $this->SendDebug("WebHook", $contend, 0);

            $compressed = gzencode($contend);
            header("Content-Encoding: gzip");
            header("Content-Length: " . strlen($compressed));
            echo $compressed;
        }
    }

    public function ForwardData($JSONString) {
        $rData = json_decode($JSONString, true);
        $jsonData = json_decode($rData['Buffer'], true);

        $this->SendDebug("ForwardData", $JSONString, 0);

        switch($jsonData["Type"]){
            case "GetLink":
                $intId = $jsonData["InstanceID"];

                $remote = $this->ReadPropertyString("RemoteAddress");
                $local = $this->ReadPropertyString("LocalAddress");
                $pw = $this->ReadPropertyString("Password");

                $link = $remote;
                if($jsonData["local"] == true){
                    $link = $local;
                }

                if(empty($link)){
                    $link = $local;
                }

                if(empty($link)){
                    return "No Address in Main modul Set!";
                }


                if(empty($pw)){
                    return $link . "/hook/JSLive?Instance=".$intId;
                }else{
                    return $link . "/hook/JSLive?Instance=".$intId."&pw=".$pw;
                }
        }


    }

    private function CheckIfLocal(string $address){
        if(empty($this->ReadPropertyString("RemoteAddress"))) return true;

        $r_address = parse_url($this->ReadPropertyString("RemoteAddress"));

        $chk_address = $r_address["host"];
        if(array_key_exists("port", $r_address)){
            $chk_address = $r_address["host"]. ":". $r_address["port"];
        }

        //$this->SendDebug("CheckIfLocal", $address. "|". $chk_address, 0);
        if($address == $chk_address) return false;

        return true;
    }
    private function ReplacePlaceholder(string $htmlData, bool $isLocal, $IntID){
        $webfrontid = $this->ReadPropertyInteger("WebfrontInstanceID");
        $address = $this->ReadPropertyString("RemoteAddress");

        if($isLocal || empty($address)){
            $address = $this->ReadPropertyString("LocalAddress");
        }
        $wsaddress = $this->GetWebsocket($address, $webfrontid);

        $htmlData = str_replace("{GLOBAL}",  $this->json_encode_advanced($this->GetConfigurationData()), $htmlData);
        $htmlData = str_replace("{ADDRESS}", $address, $htmlData);
        $htmlData = str_replace("{WSADDRESS}", $wsaddress , $htmlData);
        $htmlData = str_replace("{REMOTE_ADDRESS}", $this->ReadPropertyString("RemoteAddress"), $htmlData);
        $htmlData = str_replace("{REMOTE_WSADDRESS}", $this->GetWebsocket($this->ReadPropertyString("RemoteAddress"), $webfrontid), $htmlData);
        $htmlData = str_replace("{LOCAL_ADDRESS}", $this->ReadPropertyString("LocalAddress"), $htmlData);
        $htmlData = str_replace("{LOCAL_WSADDRESS}", $this->GetWebsocket($this->ReadPropertyString("LocalAddress"), $webfrontid), $htmlData);
        $htmlData = str_replace("{PASSWORD}", $this->ReadPropertyString("Password"), $htmlData);
        $htmlData = str_replace("{WEBFRONTPASSWORD}", $this->GetWebfrontPassword($webfrontid), $htmlData);
        $htmlData = str_replace("{WEBFRONTID}", $webfrontid, $htmlData);
        $htmlData = str_replace("{ISLOCAL}", ($isLocal ? 'true' : 'false'), $htmlData);
        $htmlData = str_replace("{INSTANCE}", $IntID, $htmlData);

        return $htmlData;
    }

    private function GetConnectAddress(){
        $connectID = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if(count($connectID) == 0){} return "";
        return CC_GetUrl($connectID);
    }
    private function GetWebsocket(string $url_str, int $webfrontid){
        if(empty($url_str)) return;
        return "ws://" . $url_str .  "/wfc/". $webfrontid ."/api/";

        $url_str = strtolower($url_str);
        $url = parse_url($url_str);

        $port = "";
        if(key_exists("port", $url)) $port = ":" . $url["port"];

        if($url['scheme'] == 'https'){
            return "wss://" . $url["host"]. $port . "/wfc/". $webfrontid ."/api/";
        }else{
            return "ws://" . $url["host"]. $port .  "/wfc/". $webfrontid ."/api/";
        }
    }
    private function GetWebfrontPassword(int $webfrontid){
        if(!IPS_InstanceExists($webfrontid)) return "";
        $data = json_decode(IPS_GetConfiguration($webfrontid), true);

        if(is_array($data) && key_exists("Password", $data)){
            return $data["Password"];
        }else{
            return "";
        }

    }
    private function GenerateRandomPassword(){
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
        $pass = array(); //remember to declare $pass as an array
        $alphaLength = strlen($alphabet) - 1; //put the length -1 in cache
        for ($i = 0; $i < 8; $i++) {
            $n = rand(0, $alphaLength);
            $pass[] = $alphabet[$n];
        }
        return implode($pass); //turn the array into a string
    }
    private function GetConfigurationData(){
        $output = json_decode(IPS_GetConfiguration($this->InstanceID), true);
        $output["InstanceID"] = $this->InstanceID;

        return $output;
    }

    public function UpdateTemplates($category){
        $templates = glob(__DIR__ ."/templates/*.html");
        $category = $this->ReadPropertyInteger("TemplateCategoryID");

        if($category == 0){
            echo "It is not allowed to use the standard directory as the template directory!";
        }

        if (!IPS_CategoryExists($category)){
            echo "The template directory does not seem to exist!";
        }

        foreach($templates as $template_path){
            $path_parts = pathinfo($template_path);
            $template_name = "(Default)" .$path_parts['filename'];

            $ScriptID = @IPS_GetScriptIDByName($template_name, $category);
            if ($ScriptID === false){
                $ScriptID = IPS_CreateScript(0);
                IPS_SetParent($ScriptID, $category);
                IPS_SetName($ScriptID, $template_name);
            }

            IPS_SetScriptContent($ScriptID, file_get_contents ($template_path));

            $this->SendDebug('UpdateTemplates', 'FileName: ' . $path_parts['filename'], 0);
        }


    }
    public function LoadConnectAddress($start = false){
        if(!$start || !empty($this->ReadPropertyString("RemoteAddress"))) return;

        $confData = json_decode(IPS_GetConfiguration($this->InstanceID), true);

        //bestimmte aktuelle einstellungen beibehalten
        $confData["RemoteAddress"] = $this->GetConnectAddress();

        IPS_SetConfiguration($this->InstanceID, json_encode($confData));
        IPS_ApplyChanges($this->InstanceID);
    }
    public function SetRandomPassword($start = false){
        if(!$start || !empty($this->ReadPropertyString("Password"))) return;

        $confData = json_decode(IPS_GetConfiguration($this->InstanceID), true);

        //bestimmte aktuelle einstellungen beibehalten
        $confData["Password"] = $this->GenerateRandomPassword();

        IPS_SetConfiguration($this->InstanceID, json_encode($confData));
        IPS_ApplyChanges($this->InstanceID);
    }
}

?>