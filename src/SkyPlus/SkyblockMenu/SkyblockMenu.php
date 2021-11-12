<?php

declare(strict_types=1);

namespace SkyPlus\SkyblockMenu;

use Closure;
use muqsit\invmenu\InvMenu;
use muqsit\invmenu\InvMenuHandler;
use muqsit\invmenu\transaction\DeterministicInvMenuTransaction;
use muqsit\invmenu\type\InvMenuTypeIds;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\item\ItemFactory;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;
use pocketmine\world\sound\ChestCloseSound;
use pocketmine\world\sound\ChestOpenSound;

class SkyblockMenu extends PluginBase {

    private const EXIT_INDEX = 49;

    private array $menus;
    private array $indexes;

    private bool $isPlugin = false;

    protected function onEnable() : void {
        $this->saveDefaultConfig();

        if ($this->getConfig()->get("version") !== "1.0") {
            $this->getLogger()->notice("Config outdated! Renaming to config_old.yml...");
            rename($this->getDataFolder() . "config.yml", $this->getDataFolder() . "config_old.yml");
            $this->saveDefaultConfig();
            $this->getConfig()->reload();
        }

        $this->menus = array_filter($this->getConfig()->get("menus"), fn (array $v) : bool => $v["enabled"] === true);
        if (($profile = $this->getConfig()->get("profile"))["enabled"] === true) {
            $this->menus["profile"] = $profile;
        }
        $this->indexes = array_column($this->menus, "index");

        // Duplicate index check
        if (count(array_unique($this->indexes)) !== count($this->indexes)) {
            $this->getLogger()->error("Duplicate index detected, disabling plugin...");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }

        // Invalid index check
        $filter = array_filter($this->indexes, function (int $value) : bool {
            return $value < 0 || $value > 53 || $value === self::EXIT_INDEX;
        });
        if (!empty($filter)) {
            $this->getLogger()->error("Invalid index detected, disabling plugin...");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }

        if (!InvMenuHandler::isRegistered()) {
            InvMenuHandler::register($this);
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool {
        if ($command->getName() === "skyblockmenu") {
            if (!$sender instanceof Player) {
                $sender->sendMessage(TextFormat::RED . "This command can only be run as player!");
                return true;
            }
            if (!$sender->hasPermission("skyblockmenu.open")) {
                $sender->sendMessage(TextFormat::RED . "You don't have permission to run this command!");
                return true;
            }
            $this->getConfig()->reload();
            $this->menus = array_filter($this->getConfig()->get("menus"), fn (array $v) : bool => $v["enabled"] === true);
            if (($profile = $this->getConfig()->get("profile"))["enabled"] === true) {
                $this->menus["profile"] = $profile;
            }
            $this->indexes = array_column($this->menus, "index");
            $this->open($sender);
        }
        return true;
    }

    private function open(Player $player) {
        $inv = InvMenu::create(InvMenuTypeIds::TYPE_DOUBLE_CHEST);
        $inv->setListener(InvMenu::readonly(Closure::fromCallable([$this, "handle"])));
        $inv->setInventoryCloseListener(function (Player $player) {
            if (!$this->isPlugin) $player->broadcastSound(new ChestCloseSound());
        });
        $inv->setName(TextFormat::colorize($this->getConfig()->get("name")));

        $contents = $inv->getInventory();

        $emptyId = $this->getConfig()->getNested("empty-slot.id");
        $emptyMeta = $this->getConfig()->getNested("empty-slot.meta");
        for ($i = 0; $i <= 53; $i++) {
            if (in_array($i, $this->indexes, true) || $i === self::EXIT_INDEX) {
                continue;
            }
            $contents->setItem($i, ItemFactory::getInstance()->get($emptyId, $emptyMeta, 1)->setCustomName("§r"));
        }

        $profileIndex = $this->getConfig()->getNested("profile.index");
        foreach ($this->menus as $menu) {
            $index = $menu["index"];
            $id = $menu["item"]["id"];
            $meta = $menu["item"]["meta"];
            $name = TextFormat::colorize($menu["name"]);

            if ($index === $profileIndex) {
                $name = strtr($name, [
                    "{health}" => $player->getHealth(),
                    "{armor_points}" => $player->getArmorPoints(),
                    "{food}" => $player->getHungerManager()->getFood(),
                    "{ping}" => $player->getNetworkSession()->getPing(),
                    "{level}" => $player->getXpManager()->getXpLevel(),
                    "{scale}" => $player->getScale()
                ]);
            }

            $contents->setItem($index, ItemFactory::getInstance()->get($id, $meta, 1)->setCustomName($name));
        }

        $contents->setItem(self::EXIT_INDEX, ItemFactory::getInstance()->get(-161, 0, 1)->setCustomName("§l§cClose"));
        $inv->send($player);
    }

    private function handle(DeterministicInvMenuTransaction $transaction) {
        $player = $transaction->getPlayer();
        if (in_array($slot = $transaction->getAction()->getSlot(), $this->indexes)) {
            if ($slot === $this->getConfig()->getNested("profile.index")) {
                return;
            }
            $arrayKey = array_search($slot, array_combine(array_keys($this->menus), $this->indexes), true);
            $command = $this->menus[$arrayKey]["command"];

            $this->isPlugin = true;
            $player->removeCurrentWindow();
            $this->isPlugin = false;

            if (!empty($command)) $this->getServer()->dispatchCommand($player, $command);
            $player->broadcastSound(new ChestOpenSound());
        } elseif ($slot === self::EXIT_INDEX) {
            $this->isPlugin = true;
            $player->removeCurrentWindow();
            $this->isPlugin = false;
            $player->broadcastSound(new ChestCloseSound());
        }
    }

}