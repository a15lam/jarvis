<?php

namespace a15lam\Jarvis;

use \a15lam\Jarvis\Workspace as WS;
use a15lam\PhpWemo\Contracts\DeviceInterface;
use a15lam\PhpWemo\Devices\WemoBulb;
use a15lam\PhpWemo\Discovery;
use a15lam\Workspace\Utility\ArrayFunc;

class Engine
{
    const SUNSET = 'SUNSET';

    const SUNRISE = 'SUNRISE';

    protected $rules = null;

    protected $debug = false;

    protected $deviceReload = false;

    protected $plexInit = [];

    public function __construct($deviceReload = false, array $rules = [])
    {
        $this->deviceReload = $deviceReload;
        if (empty($rules)) {
            $ruleFile = WS::config()->get('rule_path');
            if (!is_file($ruleFile)) {
                throw new \Exception('Cannot find the Rule File at ' . $ruleFile);
            }

            $content = file_get_contents($ruleFile);
            $rules = json_decode($content, true);

            if (null === $rules) {
                throw new \Exception('Invalid or Bad Rules supplied in ' . $ruleFile);
            }
        }
        $this->initRules($rules);
        $this->initDevices();
        $this->debug = WS::config()->get('debug', false);
    }

    protected function initRules($rules)
    {
        foreach ($rules as $k => $rule) {
            $control = ArrayFunc::get($rule, 'control');
            if (!empty($control)) {
                $time = ArrayFunc::get($control, 'time');
                if (!empty($time)) {
                    $on = ArrayFunc::get($time, 'on');
                    $off = ArrayFunc::get($time, 'off');

                    if (empty($on) || empty($off)) {
                        throw new \Exception('Invalid time configuration found in rules.');
                    }

                    if ($on === static::SUNRISE) {
                        $on = $this->getSunriseTime();
                    } elseif ($on === static::SUNSET) {
                        $on = $this->getSunsetTime();
                    }

                    if ($off === static::SUNRISE) {
                        $off = $this->getSunriseTime();
                    } elseif ($off === static::SUNSET) {
                        $off = $this->getSunsetTime();
                    }

                    $rules[$k]['control']['time']['on'] = $on;
                    $rules[$k]['control']['time']['off'] = $off;
                }
            }
        }

        $this->rules = $rules;

        if ($this->debug) {
            echo "Initialized rules." . PHP_EOL;
        }
    }

    protected function initDevices()
    {
        if ($this->deviceReload) {
            Discovery::find(true);
        }
        foreach ($this->rules as $rule) {
            $devices = ArrayFunc::get($rule, 'device');
            if (!empty($devices)) {
                if (!is_array($devices)) {
                    $devices = [$devices];
                }

                foreach ($devices as $device) {
                    /** @var DeviceInterface $d */
                    $d = $this->getDevice($device);
                    if ($d !== false) {
                        $this->{$device} = boolval($d->state());
                    }
                }
            }
        }

        if ($this->debug) {
            echo "Initialized devices." . PHP_EOL;
        }
    }

    public function run()
    {
        foreach ($this->rules as $key => $rule) {
            $controls = ArrayFunc::get($rule, 'control');
            $devices = ArrayFunc::get($rule, 'device');
            if (!is_array($devices)) {
                $devices = [$devices];
            }

            if (!empty($controls) && !empty($devices)) {
                $day = ArrayFunc::get($controls, 'day');
                $dayPass = $this->isDay($day);
                $time = ArrayFunc::get($controls, 'time');
                $timePass = $this->isTime($time);
                $plex = ArrayFunc::get($controls, 'plex');

                // day and time check passes (true) if they are empty.
                if ($dayPass && $timePass) {
                    if ($this->debug) {
                        echo "Day and Time passes. " . PHP_EOL;
                    }
                    $plexStatus = $this->getPlexStatus($plex);

                    if ($plexStatus === PlexClient::PLAYING) {
                        if ($this->debug) {
                            echo "Media is playing on " . $plex['player'] . ". Turning off lights." . PHP_EOL;
                        }
                        $this->plexInit[$key] = true;
                        $this->turnOffDevices($devices);
                    } elseif ($plexStatus === PlexClient::PAUSED) {
                        if ($this->debug) {
                            echo "Media is paused on " . $plex['player'] . ". Turning on (dim) lights." . PHP_EOL;
                        }
                        $this->plexInit[$key] = true;
                        $this->dimLights($devices, ArrayFunc::get($plex, 'dim_on_pause', 40));
                    } elseif ($plexStatus === PlexClient::STOPPED) {
                        if ($this->debug) {
                            echo "No media playing." . PHP_EOL;
                        }
                        if (ArrayFunc::get($this->plexInit, $key) === true) {
                            if ($this->debug) {
                                echo "Media is stopped on " . $plex['player'] . ". Turning on lights." . PHP_EOL;
                            }
                            $this->plexInit[$key] = false;
                            $this->turnOnDevices($devices);
                        }
                    } else {
                        if ($this->debug) {
                            echo "No plex rule." . PHP_EOL;
                        }
                        $this->turnOnDevices($devices);
                    }
                } else {
                    if ($this->debug) {
                        echo "Day and Time DO NOT pass. " . PHP_EOL;
                    }
                    $this->turnOffDevices($devices);
                }
            }
        }
    }

    protected function isDay($day)
    {
        if (empty($day)) {
            return true;
        }
        if (!is_array($day)) {
            $day = [$day];
        }

        foreach ($day as $k => $v) {
            $day[$k] = strtoupper(substr($v, 0, 3));
        }

        $today = strtoupper(date('D'));

        if (in_array($today, $day)) {
            return true;
        }

        return false;
    }

    protected function isTime($time)
    {
        if (empty($time)) {
            return true;
        }

        $on = ArrayFunc::get($time, 'on');
        $off = ArrayFunc::get($time, 'off');

        if (empty($on) || empty($off)) {
            throw new \Exception('Invalid time configuration found in rules.');
        }

        $onTime = strtotime($on);
        $offTime = strtotime($off);
        $currTime = time();

        if ($onTime < $offTime) {
            if ($currTime >= $onTime && $currTime <= $offTime) {
                return true;
            } else {
                return false;
            }
        } else {
            if ($this->debug) {
                echo "Overnight time period. " . PHP_EOL;
            }

            if (($currTime >= $onTime || $currTime <= $offTime)) {
                return true;
            } else {
                return false;
            }
        }
    }

    protected function getPlexStatus($plex)
    {
        if (empty($plex)) {
            return false;
        }

        $plexClient = new PlexClient($plex);

        return $plexClient->getPlayerStatus(ArrayFunc::get($plex, 'player'));
    }

    protected function turnOnDevices(array $devices)
    {
        foreach ($devices as $device) {
            if (@!$this->{$device}) {
                /** @var DeviceInterface $d */
                $d = $this->getDevice($device);
                if ($d !== false) {
                    if ($this->debug) {
                        echo "Turning on [" . $device . "]" . PHP_EOL;
                    }
                    if ($d->isDimmable()) {
                        $d->dim(100);
                    }
                    $d->On();

                    $this->{$device} = true;
                }
            }
        }
    }

    protected function turnOffDevices(array $devices)
    {
        foreach ($devices as $device) {
            if (@$this->{$device}) {
                /** @var DeviceInterface $d */
                $d = $this->getDevice($device);
                if ($d !== false) {
                    if ($this->debug) {
                        echo "Turning off [" . $device . "]" . PHP_EOL;
                    }
                    $d->Off();
                    $this->{$device} = false;
                }
            }
        }
    }

    protected function dimLights(array $devices, $percent = 40)
    {
        foreach ($devices as $device) {
            if (@!$this->{$device}) {
                /** @var WemoBulb $d */
                $d = $this->getDevice($device);
                if ($d !== false) {
                    if ($d->isDimmable()) {
                        if ($this->debug) {
                            echo "Dimming [" . $device . "] at " . $percent . "%" . PHP_EOL;
                        }
                        $d->dim($percent);
                    } else {
                        if ($this->debug) {
                            echo "Turning on [" . $device . "]" . PHP_EOL;
                        }
                        $d->On();
                    }
                    $this->{$device} = true;
                }
            }
        }
    }

    protected function getSunriseTime()
    {
        $latitude = WS::config()->get('latitude');
        $longitude = WS::config()->get('longitude');
        $zenith = WS::config()->get('zenith');
        $tzoffset = date("Z") / 60 / 60;

        return date('h:i A',
            strtotime(date_sunrise(time(), SUNFUNCS_RET_STRING, $latitude, $longitude, $zenith, $tzoffset)));
    }

    protected function getSunsetTime()
    {
        $latitude = WS::config()->get('latitude');
        $longitude = WS::config()->get('longitude');
        $zenith = WS::config()->get('zenith');
        $tzoffset = date("Z") / 60 / 60;

        return date('h:i A',
            strtotime(date_sunset(time(), SUNFUNCS_RET_STRING, $latitude, $longitude, $zenith, $tzoffset)));
    }

    private function getDevice($name)
    {
        try {
            return Discovery::getDeviceByName($name);
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Invalid device id supplied') !== false) {
                return false;
            } else {
                throw $e;
            }
        }
    }

    public function dumpRules()
    {
        return $this->rules;
    }
}

