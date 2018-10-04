<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

namespace pocketmine\level\format\io\region;

use pocketmine\level\format\Chunk;
use pocketmine\level\format\io\BaseLevelProvider;
use pocketmine\level\generator\GeneratorManager;
use pocketmine\level\Level;
use pocketmine\nbt\BigEndianNBTStream;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\utils\MainLogger;

abstract class RegionLevelProvider extends BaseLevelProvider{

	/**
	 * Returns the file extension used for regions in this region-based format.
	 * @return string
	 */
	abstract protected static function getRegionFileExtension() : string;

	/**
	 * Returns the storage version as per Minecraft PC world formats.
	 * @return int
	 */
	abstract protected static function getPcWorldFormatVersion() : int;

	public static function isValid(string $path) : bool{
		$isValid = (file_exists($path . "/level.dat") and is_dir($path . "/region/"));

		if($isValid){
			$files = array_filter(scandir($path . "/region/", SCANDIR_SORT_NONE), function($file){
				return substr($file, strrpos($file, ".") + 1, 2) === "mc"; //region file
			});

			$ext = static::getRegionFileExtension();
			foreach($files as $f){
				if(substr($f, strrpos($f, ".") + 1) !== $ext){
					$isValid = false;
					break;
				}
			}
		}

		return $isValid;
	}

	public static function generate(string $path, string $name, int $seed, string $generator, array $options = []){
		if(!file_exists($path)){
			mkdir($path, 0777, true);
		}

		if(!file_exists($path . "/region")){
			mkdir($path . "/region", 0777);
		}
		//TODO, add extra details
		$levelData = new CompoundTag("Data", [
			new ByteTag("hardcore", ($options["hardcore"] ?? false) === true ? 1 : 0),
			new ByteTag("Difficulty", Level::getDifficultyFromString((string) ($options["difficulty"] ?? "normal"))),
			new ByteTag("initialized", 1),
			new IntTag("GameType", 0),
			new IntTag("generatorVersion", 1), //2 in MCPE
			new IntTag("SpawnX", 256),
			new IntTag("SpawnY", 70),
			new IntTag("SpawnZ", 256),
			new IntTag("version", static::getPcWorldFormatVersion()),
			new IntTag("DayTime", 0),
			new LongTag("LastPlayed", (int) (microtime(true) * 1000)),
			new LongTag("RandomSeed", $seed),
			new LongTag("SizeOnDisk", 0),
			new LongTag("Time", 0),
			new StringTag("generatorName", GeneratorManager::getGeneratorName($generator)),
			new StringTag("generatorOptions", $options["preset"] ?? ""),
			new StringTag("LevelName", $name),
			new CompoundTag("GameRules", [])
		]);
		$nbt = new BigEndianNBTStream();
		$buffer = $nbt->writeCompressed(new CompoundTag("", [
			$levelData
		]));
		file_put_contents($path . "level.dat", $buffer);
	}

	/** @var RegionLoader[] */
	protected $regions = [];


	public function getGenerator() : string{
		return $this->levelData->getString("generatorName", "DEFAULT");
	}

	public function getGeneratorOptions() : array{
		return ["preset" => $this->levelData->getString("generatorOptions", "")];
	}

	public function getDifficulty() : int{
		return $this->levelData->getByte("Difficulty", Level::DIFFICULTY_NORMAL);
	}

	public function setDifficulty(int $difficulty){
		$this->levelData->setByte("Difficulty", $difficulty);
	}

	public function getRainTime() : int{
		return $this->levelData->getInt("rainTime", 0);
	}

	public function setRainTime(int $ticks) : void{
		$this->levelData->setInt("rainTime", $ticks);
	}

	public function getRainLevel() : float{
		if($this->levelData->hasTag("rainLevel", FloatTag::class)){ //PocketMine/MCPE
			return $this->levelData->getFloat("rainLevel");
		}

		return (float) $this->levelData->getByte("raining", 0); //PC vanilla
	}

	public function setRainLevel(float $level) : void{
		$this->levelData->setFloat("rainLevel", $level); //PocketMine/MCPE
		$this->levelData->setByte("raining", (int) ceil($level)); //PC vanilla
	}

	public function getLightningTime() : int{
		return $this->levelData->getInt("thunderTime", 0);
	}

	public function setLightningTime(int $ticks) : void{
		$this->levelData->setInt("thunderTime", $ticks);
	}

	public function getLightningLevel() : float{
		if($this->levelData->hasTag("lightningLevel", FloatTag::class)){ //PocketMine/MCPE
			return $this->levelData->getFloat("lightningLevel");
		}

		return (float) $this->levelData->getByte("thundering", 0); //PC vanilla
	}

	public function setLightningLevel(float $level) : void{
		$this->levelData->setFloat("lightningLevel", $level); //PocketMine/MCPE
		$this->levelData->setByte("thundering", (int) ceil($level)); //PC vanilla
	}

	public function doGarbageCollection(){
		$limit = time() - 300;
		foreach($this->regions as $index => $region){
			if($region->lastUsed <= $limit){
				$region->close();
				unset($this->regions[$index]);
			}
		}
	}

	/**
	 * @param int $chunkX
	 * @param int $chunkZ
	 * @param int &$regionX
	 * @param int &$regionZ
	 */
	public static function getRegionIndex(int $chunkX, int $chunkZ, &$regionX, &$regionZ){
		$regionX = $chunkX >> 5;
		$regionZ = $chunkZ >> 5;
	}

	/**
	 * @param int $regionX
	 * @param int $regionZ
	 *
	 * @return RegionLoader|null
	 */
	protected function getRegion(int $regionX, int $regionZ){
		return $this->regions[Level::chunkHash($regionX, $regionZ)] ?? null;
	}

	/**
	 * Returns the path to a specific region file based on its X/Z coordinates
	 *
	 * @param int $regionX
	 * @param int $regionZ
	 *
	 * @return string
	 */
	protected function pathToRegion(int $regionX, int $regionZ) : string{
		return $this->path . "region/r.$regionX.$regionZ." . static::getRegionFileExtension();
	}

	/**
	 * @param int $regionX
	 * @param int $regionZ
	 */
	protected function loadRegion(int $regionX, int $regionZ){
		if(!isset($this->regions[$index = Level::chunkHash($regionX, $regionZ)])){
			$path = $this->pathToRegion($regionX, $regionZ);

			$region = new RegionLoader($path, $regionX, $regionZ);
			try{
				$region->open();
			}catch(CorruptedRegionException $e){
				$logger = MainLogger::getLogger();
				$logger->error("Corrupted region file detected: " . $e->getMessage());

				$region->close(false); //Do not write anything to the file

				$backupPath = $path . ".bak." . time();
				rename($path, $backupPath);
				$logger->error("Corrupted region file has been backed up to " . $backupPath);

				$region = new RegionLoader($path, $regionX, $regionZ);
				$region->open(); //this will create a new empty region to replace the corrupted one
			}

			$this->regions[$index] = $region;
		}
	}

	public function close(){
		foreach($this->regions as $index => $region){
			$region->close();
			unset($this->regions[$index]);
		}
	}

	abstract protected function serializeChunk(Chunk $chunk) : string;

	abstract protected function deserializeChunk(string $data) : Chunk;

	protected function readChunk(int $chunkX, int $chunkZ) : ?Chunk{
		$regionX = $regionZ = null;
		self::getRegionIndex($chunkX, $chunkZ, $regionX, $regionZ);
		assert(is_int($regionX) and is_int($regionZ));

		$this->loadRegion($regionX, $regionZ);

		$chunkData = $this->getRegion($regionX, $regionZ)->readChunk($chunkX & 0x1f, $chunkZ & 0x1f);
		if($chunkData !== null){
			return $this->deserializeChunk($chunkData);
		}

		return null;
	}

	protected function writeChunk(Chunk $chunk) : void{
		$chunkX = $chunk->getX();
		$chunkZ = $chunk->getZ();

		self::getRegionIndex($chunkX, $chunkZ, $regionX, $regionZ);
		$this->loadRegion($regionX, $regionZ);

		$this->getRegion($regionX, $regionZ)->writeChunk($chunkX & 0x1f, $chunkZ & 0x1f, $this->serializeChunk($chunk));
	}

	public function getAllChunks() : \Generator{
		$iterator = new \RegexIterator(
			new \FilesystemIterator(
				$this->path . '/region/',
				\FilesystemIterator::CURRENT_AS_PATHNAME | \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS
			),
			'/\/r\.(-?\d+)\.(-?\d+)\.' . static::getRegionFileExtension() . '$/',
			\RegexIterator::GET_MATCH
		);

		foreach($iterator as $region){
			$rX = ((int) $region[1]) << 5;
			$rZ = ((int) $region[2]) << 5;

			for($chunkX = $rX; $chunkX < $rX + 32; ++$chunkX){
				for($chunkZ = $rZ; $chunkZ < $rZ + 32; ++$chunkZ){
					$chunk = $this->loadChunk($chunkX, $chunkZ);
					if($chunk !== null){
						yield $chunk;
					}
				}
			}
		}
	}
}