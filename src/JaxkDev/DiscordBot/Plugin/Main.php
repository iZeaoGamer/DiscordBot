<?php
/*
 * DiscordBot, PocketMine-MP Plugin.
 *
 * Licensed under the Open Software License version 3.0 (OSL-3.0)
 * Copyright (C) 2020-2021 JaxkDev
 *
 * Twitter :: @JaxkDev
 * Discord :: JaxkDev#2698
 * Email   :: JaxkDev@gmail.com
 */

namespace JaxkDev\DiscordBot\Plugin;

use JaxkDev\DiscordBot\Communication\BotThread;
use JaxkDev\DiscordBot\Communication\Packets\Packet;
use JaxkDev\DiscordBot\Plugin\Events\DiscordClosed;
use Phar;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\PluginBase;
use pocketmine\plugin\PluginException;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use Volatile;

class Main extends PluginBase{

    /** @var BotThread */
    private $discordBot;

    /** @var Volatile */
    private $inboundData;
    /** @var Volatile */
    private $outboundData;

    /** @var TaskHandler */
    private $tickTask;

    /** @var BotCommunicationHandler */
    private $botCommsHandler;

    /** @var Api */
    private $api;

    /** @var array */
    private $config;

    public function onLoad(){
        if(($phar = Phar::running(true)) === ""){
            throw new PluginException("Cannot be run from source.");
        }

        if(!defined("JaxkDev\DiscordBot\COMPOSER")){
            define("JaxkDev\DiscordBot\DATA_PATH", $this->getDataFolder());
            define("JaxkDev\DiscordBot\VERSION", "v".$this->getDescription()->getVersion());
            define("JaxkDev\DiscordBot\COMPOSER", $phar."/vendor/autoload.php");
        }
        if (!function_exists('JaxkDev\DiscordBot\Libs\React\Promise\resolve')) {
            /** @noinspection PhpIncludeInspection */
            require $phar.'/src/JaxkDev/DiscordBot/Libs/React/Promise/functions.php';
        }

        if(!is_dir($this->getDataFolder()."logs")){
            mkdir($this->getDataFolder()."logs");
        }
        if(!is_dir($this->getDataFolder()."debug_data")){
            mkdir($this->getDataFolder()."debug_data");
        }

        $this->saveResource("config.yml");

        $this->saveResource("HELP_ENG.txt", true); //Always keep these up-to-date.
        $this->saveResource("cacert.pem", true);

        $this->inboundData = new Volatile();
        $this->outboundData = new Volatile();
    }

    public function onEnable(){
        if(!$this->loadConfig()) return;
        if(is_file($this->getDataFolder()."events.yml")){
            // Don't delete file, DiscordChat will transfer it then delete it.
            $this->getLogger()->alert("DiscordBot v1 events.yml file found, please note this has been stripped out of ".
                "the DiscordBot core, see https://github.com/DiscordBot-PMMP/DiscordChat for similar features.");
        }
        if(extension_loaded("xdebug")){
            if(ini_get("xdebug.output_dir") === $this->getDataFolder()){
                $this->getLogger()->warning("X-Debug is running, this will cause data pack to be several minutes long.");
            }else{
                $this->getLogger()->emergency("Plugin will not run with xdebug due to the performance drops.");
                $this->getServer()->getPluginManager()->disablePlugin($this);
                return;
            }
        }
        if($this->getServer()->getTick() !== 0 and PHP_VERSION_ID >= 80000 and PHP_OS === "Darwin"){
            $this->getLogger()->emergency("Plugin not loaded on server start, self disabling to prevent crashes on MacOS running PHP8.");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }

        $this->api = new Api($this);
        $this->botCommsHandler = new BotCommunicationHandler($this);

        $this->getLogger()->debug("Starting DiscordBot Thread...");
        $this->discordBot = new BotThread($this->getServer()->getLogger(), $this->config, $this->outboundData, $this->inboundData);
        $this->discordBot->start(PTHREADS_INHERIT_CONSTANTS);

        $this->tickTask = $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(int $currentTick): void{
            $this->tick($currentTick);
        }), 1);
    }

    public function onDisable(){
        $this->stopAll(false);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool{
        if($command->getName() !== "dumpdiscord") return false;
        if(!$command->testPermission($sender)) return true;
        if(Storage::getTimestamp() === 0){
            $sender->sendMessage(TextFormat::RED."Failed to dump discord data, there is no data to dump.");
            return true;
        }

        $sender->sendMessage(TextFormat::YELLOW."Starting discord dump...");
        $file = $this->getDataFolder()."debug_data/discord_dump_".time().".txt";
        if(!Storage::saveStorage($file)){
            $sender->sendMessage(TextFormat::RED."Failed to dump discord data.");
            if(is_file($file)){
                unlink($file);
            }
        }else{
            $sender->sendMessage(TextFormat::GREEN."Successfully dumped all data to '$file'");
        }
        return true;
    }

    private function loadConfig(): bool{
        $this->getLogger()->debug("Loading configuration...");

        $config = yaml_parse_file($this->getDataFolder()."config.yml");
        if($config === false or !is_int($config["version"]??"")){
            $this->getLogger()->critical("Failed to parse config.yml");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return false;
        }

        if($config["version"] !== ConfigUtils::VERSION){
            $this->getLogger()->info("Updating your config from v{$config["version"]} to v".ConfigUtils::VERSION);
            ConfigUtils::update($config);
            rename($this->getDataFolder()."config.yml", $this->getDataFolder()."config.yml.old");
            yaml_emit_file($this->getDataFolder()."config.yml", $config);
            $this->getLogger()->info("Config updated, old config was saved to '{$this->getDataFolder()}config.yml.old'");
        }

        $this->getLogger()->debug("Verifying config...");
        $result_raw = ConfigUtils::verify($config);
        if(sizeof($result_raw) !== 0){
            $result = TextFormat::RED."There were some problems with your config.yml, see below:\n".TextFormat::RESET;
            foreach($result_raw as $value){
                $result .= "{$value}\n";
            }
            $this->getLogger()->error(rtrim($result));
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return false;
        }

        //Config is now updated and verified.
        $this->config = $config;
        return true;
    }

    private function tick(int $currentTick): void{
        $data = $this->readInboundData($this->config["protocol"]["packets_per_tick"]);

        /** @var Packet $d */
        foreach($data as $d){
            $this->botCommsHandler->handle($d);
        }

        if(($currentTick % 20) === 0){
            //Run every second. [Faster/More accurate over bots tick]
            if($this->discordBot->getStatus() === BotThread::STATUS_READY){
                $this->botCommsHandler->checkHeartbeat();
                $this->botCommsHandler->sendHeartbeat();
            }
            if($this->discordBot->getStatus() === BotThread::STATUS_CLOSED){
                $this->stopAll();
            }
        }

        if($this->inboundData->count() > 2000){
            $this->getLogger()->emergency("Too much data coming in from discord, stopping plugin+thread.  (If this issue persists, contact JaxkDev)");
            $this->stopAll();
        }

        if($this->outboundData->count() > 2000){
            $this->getLogger()->emergency("Too much data going out, stopping plugin+thread.  (If this issue persists, contact JaxkDev)");
            $this->stopAll();
        }
    }

    private function readInboundData(int $count = 1): array{
        return array_map(function($data){
            /** @var Packet $packet */
            $packet = unserialize($data);
            if(!$packet instanceof Packet){
                throw new \AssertionError("Data did not unserialize to a Packet.");
            }
            return $packet;
        }, $this->inboundData->chunk($count, false));
    }

    /**
     * @internal INTERNAL USE ONLY.
     */
    public function writeOutboundData(Packet $packet): void{
        $this->outboundData[] = serialize($packet);
    }

    public function getBotCommunicationHandler(): BotCommunicationHandler{
        return $this->botCommsHandler;
    }

    public function getApi(): Api{
        return $this->api;
    }

    public function getConfig(): Config{
        throw new PluginException("getConfig() is not used, see Main::getPluginConfig()");
    }

    public function getPluginConfig(): array{
        return $this->config;
    }

    public function stopAll(bool $stopPlugin = true): void{
        if($this->tickTask !== null){
            if(!$this->tickTask->isCancelled()){
                $this->tickTask->cancel();
            }
        }
        if($this->discordBot !== null and $this->discordBot->isRunning()){
            $this->discordBot->setStatus(BotThread::STATUS_CLOSING);
            $this->getLogger()->warning("Closing discord thread, This can take a several seconds.");
            //^ Stopping while bot is not ready (midway through data dump) causes it to wait for it to finish.
            $this->discordBot->quit();  // Joins thread
            $this->getLogger()->info("Thread closed.");
            (new DiscordClosed($this))->call();
        }
        if($stopPlugin){
            $this->getServer()->getPluginManager()->disablePlugin($this);
        }
    }
}