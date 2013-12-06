<?php


/*
__PocketMine Plugin__
name=AuthPro
description=Fast Authenticate Service
version=1.0.1-Alpha
author=Kevin Wang
class=AuthServ
apiversion=10
*/


class AuthServ implements Plugin{
	private $api, $server, $config, $sessions, $lastBroadcast = 0;
	private $userDir;
	
	private $msgReg = "[AuthPro] You must register by using: \n/register [Password]";
	private $msgLogin = "[AuthPro] You must login by using: \n/login [Password]";
	
	public function __construct(ServerAPI $api, $server = false){
		$this->api = $api;
		$this->server = ServerAPI::request();
		$this->sessions = array();
		AuthAPI::set($this);
	}


	public function init(){
		$this->config = new Config($this->api->plugin->configPath($this)."config.yml", CONFIG_YAML, array(
			"allowChat" => false,
			"messageInterval" => 10,
			"timeout" => 20,
			"allowRegister" => true,
			"forceSingleSession" => true,
		));
		$this->api->file->SafeCreateFolder($this->api->plugin->configPath($this)."Users");
		$this->userDir = $this->api->plugin->configPath($this)."Users/";

		$this->api->addHandler("player.quit", array($this, "eventHandler"), 50);
		$this->api->addHandler("player.connect", array($this, "eventHandler"), 50);
		$this->api->addHandler("player.spawn", array($this, "eventHandler"), 50);
		$this->api->addHandler("player.respawn", array($this, "eventHandler"), 50);
		$this->api->addHandler("player.chat", array($this, "eventHandler"), 50);
		$this->api->addHandler("console.command", array($this, "eventHandler"), 50);
		$this->api->addHandler("op.check", array($this, "eventHandler"), 50);
		$this->api->schedule(80, array($this, "checkTimer"), array(), true);
		$this->api->console->register("unregister", "<password>", array($this, "commandHandler"));
		$this->api->ban->cmdWhitelist("unregister");
		console("[INFO] Auth Enabled! ");
	}


	public function commandHandler($cmd, $params, $issuer, $alias){
		$output = "";
		switch($cmd){
			case "unregister":
				if(!($issuer instanceof Player)){
					$output .= "Please run this command inside the game.\n";
					break;
				}
				if($this->sessions[$issuer->CID] !== true){
					$output .= "Please login first.\n";
					break;
				}
				if($d !== false and $d["hash"] === $this->hash($issuer->iusername, implode(" ", $params))){
					unlink($this->userDir . strtolower($issuer->iusername) . ".yml");
					$this->logout($issuer);
					$output .= "[AuthPro] Unregistered correctly.\n";
				}else{
					$output .= "[AuthPro] Error during authentication.\n";
				}
				break;
		}
		return $output;
	}


	public function checkTimer(){
		if($this->config->get("allowRegister") !== false and ($this->lastBroadcast + $this->config->get("messageInterval")) <= time()){
			$broadcast = true;
			$this->lastBroadcast = time();
		}else{
			$broadcast = false;
		}


		if(($timeout = $this->config->get("timeout")) <= 0){
			$timeout = false;
		}


		foreach($this->sessions as $CID => $timer){
			if($timer !== true and $timer !== false){				
				if($broadcast === true){
					$d = file_exists($this->userDir . strtolower($this->server->clients[$CID]->iusername) . ".yml");
					if($d === false){					
						$this->server->clients[$CID]->sendChat($this->msgReg);
					}else{
						$this->server->clients[$CID]->sendChat($this->msgLogin);
					}
				}
				if($timeout !== false and ($timer + $timeout) <= time()){
					//KVCHANGE - ADD IF
					if($this->server->clients[$CID] instanceof Player)
					{
						$this->server->clients[$CID]->close("authentication timeout");
					}
				}
			}
		}


	}


	private function hash($salt, $password){
		return($password);
		//return Utils::strToHex(hash("sha512", $password . $salt, true) ^ hash("whirlpool", $salt . $password, true));
	}


	public function checkLogin(Player $player, $password){
		if(!(file_exists($this->userDir . strtolower($player->iusername) . ".yml"))){
			return(false);
		}
		$d = new Config($this->userDir . strtolower($player->iusername) . ".yml", CONFIG_YAML, array());
		if($d->get("hash") === $this->hash($player->iusername, $password)){
			return true;
		}
		return false;
	}


	public function login(Player $player){
		$d = new Config($this->userDir . strtolower($player->iusername) . ".yml", CONFIG_YAML, array());
		if($d !== false){
			$d->set("logindate", time());
			$d->save();
			//$this->playerFile->set($player->iusername, $d);
			//KVCHANGE - ADD
			//Do not save the file too many times, it will take too much CPU usage. 
			//$this->playerFile->save();
		}
		$this->sessions[$player->CID] = true;
		$player->blocked = false;
		$player->sendChat("[AuthPro] You've been authenticated.");
		return true;
	}


	public function logout(Player $player){
		$this->sessions[$player->CID] = time();
		$player->blocked = true;
	}


	public function register(Player $player, $password){	
		if(file_exists($this->userDir . strtolower($player->iusername) . ".yml")){return(false);}
		$d = new Config($this->userDir . strtolower($player->iusername) . ".yml", CONFIG_YAML, array());
		$d->set("registerdate", time());
		$d->set("logindate", time());
		$d->set("hash", $this->hash($player->iusername, $password));
		$d->save();
		unset($d);
		return(true);
	}


	public function eventHandler($data, $event){
		switch($event){
			case "player.quit":
				unset($this->sessions[$data->CID]);
				break;
			case "player.connect":
				if($this->config->get("forceSingleSession") === true){
					$p = $this->api->player->get($data->iusername);
					if(($p instanceof Player) and $p->iusername === $data->iusername){
						return false;
					}
				}
				$this->sessions[$data->CID] = false;
				break;
			case "player.spawn":
				if($this->sessions[$data->CID] !== true){
					$this->sessions[$data->CID] = time();
					$data->blocked = true;
					if($this->config->get("allowRegister") !== false){
						$d = file_exists($this->userDir . strtolower($data->iusername) . ".yml");
						if($d === false){					
							$data->sendChat($this->msgReg);
						}else{
							$data->sendChat($this->msgLogin);
						}
					}
				}
				break;
			case "console.command":
				if(($data["issuer"] instanceof Player) and $this->sessions[$data["issuer"]->CID] !== true){
					if($this->config->get("allowRegister") !== false and $data["cmd"] === "login" and $this->checkLogin($data["issuer"], implode(" ", $data["parameters"])) === true){
						$this->login($data["issuer"]);
						return true;
					}elseif($this->config->get("allowRegister") !== false and $data["cmd"] === "register" and $this->register($data["issuer"], implode(" ", $data["parameters"])) === true){
						$data["issuer"]->sendChat("[AuthPro] You've been sucesfully registered.");
						$this->login($data["issuer"]);
						return true;
					}elseif($this->config->get("allowRegister") !== false and $data["cmd"] === "login" or $data["cmd"] === "register"){
						$data["issuer"]->sendChat("[AuthPro] Error during authentication.");
						return true;
					}
					return false;
				}
				break;
			case "player.chat":
				if($this->config->get("allowChat") !== true and $this->sessions[$data["player"]->CID] !== true){
					return false;
				}
				break;
			case "op.check":
				$p = $this->api->player->get($data);
				if(($p instanceof Player) and $this->sessions[$p->CID] !== true){
					return false;
				}
				break;
			case "player.respawn":
				if($this->sessions[$data->CID] !== true){
					$data->blocked = true;
				}
				break;
		}
	}


	public function __destruct(){
		$this->config->save();
	}


}


class AuthAPI{
	private static $object;
	public static function set(AuthServ $plugin){
		if(AuthAPI::$object instanceof AuthServ){
			return false;
		}
		AuthAPI::$object = $plugin;
	}


	public static function get(){
		return AuthAPI::$object;
	}


	public static function login(Player $player){
		return SimpleAuthAPI::$object->login($player);
	}


	public static function logout(Player $player){
		return SimpleAuthAPI::$object->logout($player);
	}
}

?>
