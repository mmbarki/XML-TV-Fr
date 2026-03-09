# XML TV Fr

XML TV Fr est un service permettant de récupérer un guide des programmes au format XMLTV.

Site web et documentation : https://xmltvfr.fr/


# Installation

## Natif
Pour installer XML TV Fr, vous devez posséder:

PHP >=8.0 avec les extensions
 - curl
 - zip
 - mbstring
 - xml
 - json
 - dom
 - simplexml
 - xmlreader
 - libxml
 - pcntl
 - posix
 - intl

Ainsi que Composer.

Un `composer install` est requis pour utiliser le script.

## Utilisation de Docker

Vous pouvez utiliser XML TV Fr avec Docker.

Un fichier [Dockerfile](./Dockerfile) à la racine du projet vous permet d'installer et configurer XML TV Fr en une seule commande.

### Construire l'image
Pour construire l'image, tapez la commande:
```bash
docker build -t xmltvfr .
```
Note: Cette commande doit être lancée après chaque mise à jour de XML TV Fr.

# Configuration

Cette partie va vous permettre de configurer XML TV Fr.

## Liste des chaines (config/channels.json)

La liste des chaines doit être indiquée dans le fichier `channels.json` au format JSON. Chaque chaine correspond à l'ID d'une chaine (Exemple : `France2.fr`) présente dans les fichiers de chaines par services (dossier `resources/channel_config/`).
La structure d'un item se fait comme ceci :
```json
"IdDelaChaineDansLeProgramme": {
  "name": "Nom de la chaine",
  "alias": "IDdeLaChaineDansLeXMLTV",
  "icon": "http://icone de la chaine",
  "priority": ["Service1", "Service2"]
}
```
Les champs `name`, `icon`, `alias` et `priority` sont optionnels.

Le champ `priority` donne un ordre de priorité différent de celui par défaut en indiquant les noms des services (nom des classes dans le dossier `src/Component/Provider/`). Dans l'exemple, Service1 sera appelé en premier et Service2 ne sera appelé que si Service1 échoue. Si aucun programme n'est trouvé sur tous les services, la chaine est indiquée HS pour le jour concerné.

Le champ `alias` permet de donner un ID alternatif à une chaine que celui renseigné par défaut. Si le champ est absent, c'est l'ID par défaut renseigné dans XML TV Fr qui sera affiché.

## Configuration du programme (config/config.json)

Le fichier `config.json` est au format JSON.
```json
{
  "fetch_policies": {
    "cache-first": [1, 2, 3, 4, 5, 6, 7],
    "network-first": [0],
    "cache-only": [-2, -1]
  },
  "cache_ttl": 8,
  "cache_physical_ttl": 8,
  "output_path": "var/export/",
  "time_limit": null,
  "memory_limit": -1,
  "export_handlers": [
    {"class": "GZExport", "params": {}},
    {"class": "ZIPExport", "params": {}}
  ],
  "delete_raw_xml": false,
  "enable_dummy": false,
  "priority_orders": {"Telerama": 0.2, "UltraNature": 0.5},
  "guides": [
    {"channels": ["config/channels.json"], "filename": "xmltv"}
  ],
  "nb_threads": 1,
  "min_endtime": 84600,
  "ui": "MultiColumnUI"
}
```

### Description des paramètres

| Paramètre | Type | Défaut | Description |
|-----------|------|--------|-------------|
| `fetch_policies` | objet | voir ci-dessous | Politique de récupération par jour |
| `cache_ttl` | entier | `8` | Nombre de jours avant qu'un cache EPG soit considéré expiré |
| `cache_physical_ttl` | entier | `8` | Nombre de jours avant la suppression physique du cache sur le disque |
| `output_path` | chaîne | `"var/export/"` | Dossier de destination des fichiers XMLTV |
| `time_limit` | entier\|null | `null` | Temps d'exécution max en secondes (`null` = illimité) |
| `memory_limit` | entier | `-1` | Quantité de mémoire max (`-1` = illimitée) |
| `export_handlers` | tableau | GZ + ZIP | Liste des formats d'export (voir ci-dessous) |
| `delete_raw_xml` | booléen | `false` | Supprimer le XML brut après compression |
| `enable_dummy` | booléen | `false` | Afficher un EPG mire si aucun programme n'est trouvé pour une chaine |
| `priority_orders` | objet | `{}` | Modifier la priorité globale de certains providers |
| `guides` | tableau | voir ci-dessous | Liste des fichiers XMLTV à générer |
| `nb_threads` | entier | `1` | Nombre de threads parallèles (nécessite l'accès shell) |
| `min_endtime` | entier | `84600` | Heure minimale (en secondes depuis minuit) à laquelle le dernier programme doit se terminer pour que le jour soit considéré complet (84600 = 23h30) |
| `ui` | chaîne | `"MultiColumnUI"` | Interface terminal : `MultiColumnUI` ou `ProgressiveUI` |

### Politique de récupération (`fetch_policies`)

Les jours sont exprimés en décalage par rapport à aujourd'hui : `0` = aujourd'hui, `1` = demain, `-1` = hier, etc.

- **`network-first`** : Récupère toujours les données depuis le provider (réseau en priorité). Utilisé par défaut pour aujourd'hui.
- **`cache-first`** : Utilise le cache si disponible et valide, sinon récupère depuis le provider.
- **`cache-only`** : Utilise uniquement le cache, sans aucune requête réseau.

### Formats d'export (`export_handlers`)

Le champ `export_handlers` est un tableau d'objets avec les champs `class` et `params`. Les classes disponibles sont :
- `GZExport` : compression `.gz`
- `ZIPExport` : compression `.zip`
- `CommandLineExport` : export via commande personnalisée

### Guides XMLTV (`guides`)

Chaque entrée du tableau `guides` génère un fichier XMLTV distinct. Le champ `channels` est un **tableau** de fichiers de chaines (permettant de combiner plusieurs fichiers) et `filename` est le nom du fichier de sortie (sans extension).

```json
"guides": [
  {"channels": ["config/channels.json", "config/channels_extra.json"], "filename": "xmltv"}
]
```

# Lancer le script
## Natif
Pour démarrer la récupération du guide des programmes, lancez cette commande dans votre terminal (dans le dossier du programme).
```shell
php manager.php export
```

Options disponibles :
```shell
php manager.php export --skip-generation  # Ne génère pas l'EPG, exporte uniquement
php manager.php export --keep-cache       # Conserve le cache après la génération
php manager.php fetch-channel <channel-id> <date> <provider> <output_path>  # Récupère une chaine spécifique
php manager.php update-default-logos      # Met à jour les logos par défaut
php manager.php help                      # Affiche l'aide
```

## Docker
Pour récupérer votre XML, tapez la commande:
```bash
docker run -v ./var/export:/app/var/export -v ./config/:/app/config xmltvfr
```
Vous pouvez remplacer **./var/export** par le dossier de sortie que vous souhaitez.

# Sortie

## Logs

Les logs sont stockés dans le dossier `var/logs/` au format JSON.

## XML TV

Les fichiers de sortie XML sont stockés dans le dossier `var/export/` au format XML, ZIP et GZ (selon les `export_handlers` configurés).

# Ajouter des services

Il est possible d'ajouter des services (`Provider`) autres que ceux fournis. Pour cela, il faut ajouter une classe dans le dossier `src/Component/Provider/` qui implémente l'interface `ProviderInterface` et étend la classe `AbstractProvider`.

La méthode `getPriority()` doit retourner un flottant entre 0 et 1 pour indiquer la priorité par rapport à d'autres services (comparez les valeurs des autres scripts pour vous situer). La méthode `getPriority()` est déjà implémentée dans la classe abstraite.

La méthode `constructEPG(channel, date)` construit l'EPG pour une chaine à une date donnée. Elle retourne un objet `Channel` si la tâche s'est déroulée avec succès, sinon `false`.

Exemple :
```php
function constructEPG(string $channel, string $date): Channel|bool
{
    parent::constructEPG($channel, $date);
    $error = false;
    foreach ($results as $result) {
        $program = $this->channelObj->addProgram(strtotime($result['start']), strtotime($result['end']));
        $program->addTitle($result['title'], 'fr'); // argument langue optionnel, par défaut = "fr"
        $program->addIcon('myIconUrl');
        $program->addCategory(...);
        $program->addSubTitle(...);
        // ...
    }
    if ($error) {
        return false;
    }
    return $this->channelObj;
}
```

Le nom de la classe du service doit correspondre à son nom de fichier.

Il faut également ajouter la liste des chaines supportées par le provider dans un fichier JSON dans le dossier `resources/channel_config/` (ex: `channels_myprovider.json`).
