<?php

namespace a15lam\Jarvis;

use a15lam\Workspace\Utility\ArrayFunc;
use a15lam\Workspace\Utility\DataFormat;
use a15lam\Jarvis\Workspace as WS;

class PlexClient
{
    /**
     * PMS port.
     */
    const PORT = '32400';
    /**
     * PMS API to use.
     */
    const API = 'status/sessions';
    /**
     * Media stopped status
     */
    const STOPPED = 0;
    /**
     * Media playing status
     */
    const PLAYING = 1;
    /**
     * Media paused status
     */
    const PAUSED = 2;

    /**
     * URL for the PMS
     *
     * @type string
     */
    protected $url;

    /**
     * PlexClient constructor.
     *
     * @param string|array $config
     *
     * @throws \Exception
     */
    public function __construct($config)
    {
        $host = $config;
        $port = static::PORT;
        $api = static::API;

        if (is_array($config)) {
            $host = ArrayFunc::get($config, 'host');
            if (empty($host)) {
                throw new \Exception("PlexClient config is missing 'host'");
            }

            $port = ArrayFunc::get($config, 'port', static::PORT);
            $api = ArrayFunc::get($config, 'api', static::API);
            $token = ArrayFunc::get($config, 'token');
        }

        $this->url = 'http://' . $host . ':' . $port . '/' . $api . '?X-Plex-Token=' . $token;
    }

    /**
     * Gets PMS status
     *
     * @return array
     * @throws \Exception
     */
    public function getStatus()
    {
        try {
            $options = [
                CURLOPT_URL            => $this->url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_VERBOSE        => false,
                CURLOPT_HTTPHEADER     => ["Accept: application/json"]
            ];

            $ch = curl_init();
            curl_setopt_array($ch, $options);
            $response = curl_exec($ch);
            $response = json_decode($response, true);
            return $response;
        } catch (\Exception $e) {
            throw new \Exception("Failed to make curl request " . $e->getMessage());
        }
    }

    /**
     * Gets player (client) device name.
     *
     * @return string|null
     */
    public function getPlayers()
    {
        $status = $this->getStatus();
        $out = [];

        if (is_array($status)) {
            $info = null;
            if(isset($status['MediaContainer'])) {
                if(isset($status['MediaContainer']['Metadata'])) {
                    $info = $status['MediaContainer']['Metadata'];
                }
                elseif(isset($status['MediaContainer']['Video'])) {
                    $info = $status['MediaContainer']['Video'];
                }
		elseif(isset($status['MediaContainer'][0]['Metadata'])) {
                    $info = $status['MediaContainer'][0]['Metadata'];
                }
            }

            if (!empty($info)) {
                if (isset($info['Player'])) {
                    $out[] = $info['Player'];
                } else {
                    $f = true;
                    for ($i = 0; $f === true; $i++) {
                        if (isset($info[$i]['Player'])) {
                            $out[] = $info[$i]['Player'];
                        } else {
                            $f = false;
                        }
                    }
                }

                return $out;
            }
        }
        WS::log()->debug("No status returned from Plex server. Probably no media is playing.");

        return null;
    }

    /**
     * Fetch media playback status for a player/client.
     *
     * @param static $playerName
     *
     * @return int
     */
    public function getPlayerStatus($playerName)
    {
        $players = $this->getPlayers();

        if (!empty($players)) {
            foreach ($players as $player) {
                if ($playerName === $player['title']) {
                    if ($player['state'] === 'playing') {
                        WS::log()->debug('Media playing on ' . $playerName);

                        return static::PLAYING;
                    } elseif ($player['state'] === 'paused') {
                        WS::log()->debug('Media paused on ' . $playerName);

                        return static::PAUSED;
                    }
                    WS::log()->debug('Media stopped or no media playing on ' . $playerName);
                }
            }
        }

        return static::STOPPED;
    }
}
