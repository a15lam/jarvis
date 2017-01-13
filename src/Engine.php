<?php

namespace a15lam\Jarvis;

use \a15lam\Jarvis\Workspace as WS;
use a15lam\PhpWemo\Contracts\DeviceInterface;
use a15lam\PhpWemo\Devices\WemoBulb;
use a15lam\PhpIot\Discovery;
use a15lam\Workspace\Utility\ArrayFunc;

class Engine
{
    /** Sunset constant */
    const SUNSET = 'SUNSET';

    /** Sunrise constant */
    const SUNRISE = 'SUNRISE';

    /** @var array|null */
    protected $rules = null;

    /** @var bool */
    protected $debug = false;

    /** @var bool */
    protected $deviceReload = false;

    /** @var array */
    protected $plexInit = [];

    /** @var array */
    protected $plexStatus = [];

    /**
     * Engine constructor.
     *
     * @param bool  $deviceReload
     * @param array $rules
     *
     * @throws \Exception
     */
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

    /**
     * Initializes rules.
     *
     * @param array $rules
     *
     * @throws \Exception
     */
    protected function initRules(array $rules)
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
        WS::log()->info('Initialized all rules.');
    }

    /**
     * Initializes devices per rule.
     */
    protected function initDevices()
    {
        if ($this->deviceReload) {
            Discovery::find(true);   // This caches all discovered devices locally.
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
        WS::log()->info('Initialized devices.');
    }

    /**
     * Runs all rule checks (runs in a loop by daemon)
     */
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
                $timePass = $this->isTime($time, $key);
                $plex = ArrayFunc::get($controls, 'plex');

                // Day and Time check passes (true) if they are empty.
                if ($dayPass && $timePass) {
                    WS::log()->debug('Day and time passed for rule ' . $key);

                    $plexStatus = $this->getPlexStatus($plex);

                    WS::log()->debug('Last plex status for rule ' .
                        $key .
                        ':' .
                        ArrayFunc::get($this->plexStatus, $key));
                    if ($plexStatus === PlexClient::PLAYING) {
                        $force = false;
                        if (ArrayFunc::get($this->plexStatus, $key) === PlexClient::STOPPED) {
                            WS::log()->debug('Plex status went from STOPPED to PLAYING. Forcing device turn off.');
                            $force = true;
                        }
                        $this->plexInit[$key] = true;
                        $this->turnOffDevices($devices, $force);
                    } elseif ($plexStatus === PlexClient::PAUSED) {
                        $this->plexInit[$key] = true;
                        $this->dimLights($devices, ArrayFunc::get($plex, 'dim_on_pause', 40));
                    } elseif ($plexStatus === PlexClient::STOPPED) {
                        if (ArrayFunc::get($this->plexInit, $key) === true) {
                            $force = false;
                            if (ArrayFunc::get($this->plexStatus, $key) === PlexClient::PAUSED) {
                                WS::log()->debug('Plex status went from PAUSED to STOPPED. Forcing device turn on.');
                                $force = true;
                            }
                            $this->plexInit[$key] = false;
                            $this->turnOnDevices($devices, $force);
                        }
                    } else {
                        WS::log()->debug('No plex control for rule ' . $key);
                        $this->turnOnDevices($devices);
                    }
                    $this->plexStatus[$key] = $plexStatus;
                } else {
                    WS::log()->debug("Day and Time DO NOT pass for rule " . $key);
                    $this->turnOffDevices($devices);
                }
            }
        }
    }

    /**
     * Checks to see if Day rule matches.
     * If no day rule specified, returns true.
     *
     * @param string|array $day
     *
     * @return bool
     */
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

    /**
     * Checks to see if Time rule matches.
     * If no time rule specified, returns true.
     *
     * @param mixed|array $time
     * @param int         $ruleNum
     *
     * @return bool
     * @throws \Exception
     */
    protected function isTime($time, $ruleNum)
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
            WS::log()->debug('Handling overnight time period for rule ' . $ruleNum);

            if (($currTime >= $onTime || $currTime <= $offTime)) {
                return true;
            } else {
                return false;
            }
        }
    }

    /**
     * Fetches media playback status for a plex rule control.
     *
     * @param array|mixed $plex
     *
     * @return bool|int
     */
    protected function getPlexStatus($plex)
    {
        if (empty($plex)) {
            return false;
        }
        $plexClient = new PlexClient($plex);

        return $plexClient->getPlayerStatus(ArrayFunc::get($plex, 'player'));
    }

    /**
     * Turns on supplied devices
     *
     * @param array $devices
     * @param bool  $force
     */
    protected function turnOnDevices(array $devices, $force = false)
    {
        foreach ($devices as $device) {
            if (@!$this->{$device} || $force === true) {
                /** @var DeviceInterface $d */
                $d = $this->getDevice($device);
                if ($d !== false) {
                    WS::log()->info("Turning on [" . $device . "]");
                    if ($d->isDimmable()) {
                        $d->dim(100);
                    }
                    $d->On();

                    $this->{$device} = true;
                }
            }
        }
    }

    /**
     * Turns off supplied devices.
     *
     * @param array $devices
     * @param bool  $force
     */
    protected function turnOffDevices(array $devices, $force = false)
    {
        foreach ($devices as $device) {
            if (@$this->{$device} || $force === true) {
                /** @var DeviceInterface $d */
                $d = $this->getDevice($device);
                if ($d !== false) {
                    WS::log()->info("Turning off [" . $device . "]");
                    $d->Off();
                    $this->{$device} = false;
                }
            }
        }
    }

    /**
     * Dims supplied lights to specified brightness.
     *
     * @param array $devices
     * @param int   $percent
     */
    protected function dimLights(array $devices, $percent = 40)
    {
        foreach ($devices as $device) {
            if (@!$this->{$device}) {
                /** @var WemoBulb $d */
                $d = $this->getDevice($device);
                if ($d !== false) {
                    if ($d->isDimmable()) {
                        WS::log()->info("Dimming [" . $device . "] at " . $percent . "%");
                        $d->dim($percent);
                    } else {
                        WS::log()->info("Dimming not supported. Turning on [" . $device . "]");
                        $d->On();
                    }
                    $this->{$device} = true;
                }
            }
        }
    }

    /**
     * Returns the local sunrise time based on config.
     *
     * @return false|string
     */
    protected function getSunriseTime()
    {
        $latitude = WS::config()->get('latitude');
        $longitude = WS::config()->get('longitude');
        $zenith = WS::config()->get('zenith');
        $tzoffset = date("Z") / 60 / 60;

        return date('h:i A',
            strtotime(date_sunrise(time(), SUNFUNCS_RET_STRING, $latitude, $longitude, $zenith, $tzoffset)));
    }

    /**
     * Returns the local sunset time based on config.
     *
     * @return false|string
     */
    protected function getSunsetTime()
    {
        $latitude = WS::config()->get('latitude');
        $longitude = WS::config()->get('longitude');
        $zenith = WS::config()->get('zenith');
        $tzoffset = date("Z") / 60 / 60;

        return date('h:i A',
            strtotime(date_sunset(time(), SUNFUNCS_RET_STRING, $latitude, $longitude, $zenith, $tzoffset)));
    }

    /**
     * Fetches a device object.
     *
     * @param $name
     *
     * @return bool|DeviceInterface
     * @throws \Exception
     */
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

    /**
     * Dumps all rules when debug mode is on.
     *
     * @return array|null
     */
    public function dumpRules()
    {
        if ($this->debug) {
            return $this->rules;
        }
    }
}

