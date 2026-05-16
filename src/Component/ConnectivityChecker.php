<?php

declare(strict_types=1);

namespace racacax\XmlTv\Component;

use racacax\XmlTv\Configurator;

class ConnectivityChecker
{
    /**
     * Tests internet connectivity by making a request to the given URL.
     * If the connection fails, logs an error and exits the process with code 1.
     */
    public static function checkOrExit(string $url): void
    {
        Logger::log("\e[33m[CONNEXION] Perte de connexion détectée sur plusieurs providers. Test de connectivité vers $url...\e[39m\n");
        $client = Configurator::getDefaultClient();

        try {
            $client->get($url, ['connect_timeout' => 5, 'timeout' => 10]);
            Logger::log("\e[32m[CONNEXION] Connectivité OK. Poursuite de la récupération.\e[39m\n");
        } catch (\Throwable $_) {
            Logger::log("\e[31m[CONNEXION] Pas de connectivité internet. Arrêt du processus.\e[39m\n");
            static::doExit();
        }
    }

    /**
     * Terminates the process. Extracted to allow overriding in tests.
     */
    protected static function doExit(): void
    {
        exit(1);
    }
}
