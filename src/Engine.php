<?php

namespace a15lam\Jarvis;

use \a15lam\Jarvis\Workspace as WS;
use a15lam\PhpWemo\Contracts\DeviceInterface;
use a15lam\PhpWemo\Discovery;
use a15lam\Workspace\Utility\ArrayFunc;

class Engine
{
    const SUNSET = 'SUNSET';

    const SUNRISE = 'SUNRISE';

    protected $rules = null;

    protected $debug = false;

    public function __construct(array $rules = [])
    {
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
        $this->initDeviceStatus();
        $this->debug = WS::config()->get('debug', false);
    }

    protected function initRules($rules)
    {
        // translate sunrise /sunset time here

        $this->rules = $rules;
    }

    protected function initDeviceStatus()
    {

    }

    public function run()
    {
        foreach ($this->rules as $rule) {
            $controls = ArrayFunc::get($rule, 'control');
            $device = ArrayFunc::get($rule, 'device');

            if (!empty($controls) && !empty($device)) {
                foreach ($controls as $name => $options) {
                    switch ($name) {
                        case 'time':
                            $on = ArrayFunc::get($options, 'on');
                            $off = ArrayFunc::get($options, 'off');
                            $onTime = 0;
                            $offTime = 0;
                            $currTime = time();

                            if (static::SUNSET === $on) {
                            } else {
                                $onTime = strtotime($on);
                            }

                            if (static::SUNRISE === $off) {
                            } else {
                                $offTime = strtotime($off);
                            }

                            if ($onTime < $offTime) {
                                if ($currTime >= $onTime && $currTime <= $offTime) {
                                    if(@!$this->{$device}) {
                                        if ($this->debug) {
                                            echo "Turning on [" . $device . "]" . PHP_EOL;
                                        }
                                        /** @var DeviceInterface $device */
                                        $d = Discovery::getDeviceByName($device);
                                        $d->On();
                                        $this->{$device} = true;
                                    }
                                } else {
                                    if(@$this->{$device}) {
                                        if ($this->debug) {
                                            echo "Turning off [" . $device . "]" . PHP_EOL;
                                        }
                                        /** @var DeviceInterface $device */
                                        $d = Discovery::getDeviceByName($device);
                                        $d->Off();
                                        $this->{$device} = false;
                                    }
                                }
                            } else {
                                if($this->debug){
                                    echo "Overnight time period. " . PHP_EOL;
                                }

                                if (($currTime >= $onTime || $currTime <= $offTime)) {
                                    if (@!$this->{$device}) {
                                        if ($this->debug) {
                                            echo "Turning on [" . $device . "]" . PHP_EOL;
                                        }
                                        /** @var DeviceInterface $device */
                                        $d = Discovery::getDeviceByName($device);
                                        $d->On();
                                        $this->{$device} = true;
                                    }
                                } else {
                                    if (@$this->{$device}) {
                                        if ($this->debug) {
                                            echo "Turning off [" . $device . "]" . PHP_EOL;
                                        }
                                        /** @var DeviceInterface $device */
                                        $d = Discovery::getDeviceByName($device);
                                        $d->Off();
                                        $this->{$device} = false;
                                    }
                                }
                            }
                            break;
                        default:
                            break;
                    }
                }
            }
        }
    }

    public function dumpRules()
    {
        return $this->rules;
    }
}

