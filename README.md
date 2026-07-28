# jee4lm5 — La Marzocco pour Jeedom

Plugin **Jeedom 4.6** pour piloter une machine espresso **La Marzocco** via le cloud
La Marzocco, avec support du **Brew By Weight** (balance Acaia Lunar).

- **Version plugin** : 4.6 · **Licence** : AGPL · **Auteur** : neuralldev
- **Documentation utilisateur** : [docs/fr_FR/index.md](docs/fr_FR/index.md)
- **Changelog** : [docs/fr_FR/changelog.md](docs/fr_FR/changelog.md)

---

## Machines supportées

Développé et validé sur **Linea Mini**. Fonctionne également sur **Micra** et **GS3**,
mais toutes les spécificités GS3 ne sont pas couvertes — le plugin est orienté vers
les machines les plus répandues.

---

## Ce que fait le plugin

| Domaine | Fonctions |
|---|---|
| **Alimentation** | Allumage / extinction (`BrewingMode` / `StandBy`), anneau SVG animé de progression de chauffe |
| **Chaudière café** | Consigne + température réelle, statut Off / En chauffe / Prêt |
| **Chaudière vapeur** | Activation ON/OFF, consigne (selon modèle) |
| **Prétrempage / Préinfusion** | Activation du mode, durée de mouillage et durée de pause (sliders en secondes) |
| **Brew By Weight** | Doses A / B (grammes), mode Dose 1 / Dose 2 / Continu, état de connexion et **niveau de batterie** de la balance |
| **Smart Standby** | Activation, durée, déclencheur (dernier café / allumage) |
| **Maintenance** | Cycle de backflush, alerte manque d'eau |

Les commandes utilisent les **types génériques Jeedom**, ce qui permet de les remonter
directement vers HomeKit / Alexa / Google via Homebridge : thermostats pour les
chaudières, interrupteurs pour l'allumage et la sélection de dose.

Un **widget dashboard HTML dédié** (`core/template/dashboard/jee4lm5.html`) regroupe
l'ensemble, avec mise à jour temps réel via l'événement `jeedom.cmd.update` (pas de
rechargement du DOM).

---

## Prérequis

- Jeedom **4.6+**
- Un compte La Marzocco (celui de l'application mobile)
- Une machine connectée au cloud La Marzocco
- Python **3.11+** (fourni par Jeedom sur Debian 12)

---

## Installation

1. Installer le plugin depuis le Market Jeedom, puis **l'activer**.
2. **Installer les dépendances** (bouton sur la page du plugin) — installe via pip3 :
   `jeedomdaemon`, `aiohttp`, `mashumaro`, `cryptography`, `bleak`,
   `bleak-retry-connector` et leurs dépendances (voir [plugin_info/packages.json](plugin_info/packages.json)).
3. **Se connecter** : bouton de connexion → email + mot de passe La Marzocco.
   Le credential obtenu est stocké localement dans `data/credential.json` et
   n'est envoyé qu'aux serveurs La Marzocco.
4. **Démarrer le démon**.
5. Lancer une **détection** : la machine est créée en équipement Jeedom.
   La première réception du dashboard crée automatiquement toutes les commandes.

Détail pas à pas et dépannage : [docs/fr_FR/index.md](docs/fr_FR/index.md).

---

## Architecture

```
PHP (Jeedom core, synchrone)                Démon Python (asyncio, long-running)
────────────────────────────                ───────────────────────────────────
core/class/jee4lm5.class.php   ──TCP JSON──▶ resources/jee4lm5d/jee4lm5d.py
  deamon_send()  (eq → démon)   127.0.0.1     class Jee4LM(BaseDaemon)
                                  :50044        on_message() → dispatch
core/php/jee4lm5d.php          ◀─HTTP POST──   send_to_jeedom()
  dispatch {detect|settings|dash|schedule}
core/ajax/jee4lm5.ajax.php     (login, sync)
desktop/php|js/jee4lm5.*       (page de configuration eqLogic)
```

- **PHP → démon** : socket TCP brut, écriture JSON, fermeture. Fire-and-forget.
- **Démon → PHP** : callback HTTP POST vers `core/php/jee4lm5d.php`, dispatché sur
  la clé de premier niveau (`cmd=='detect'`, `settings`, `dash`, `schedule`).
- **Cycle de vie du démon** : entièrement piloté depuis PHP
  (`deamon_start` / `deamon_stop` / `deamon_info`) via `system::*` et un pidfile.
- **`_dash_loop`** : tâche asyncio qui interroge `get_dashboard()` **toutes les 15 s**
  et pousse le résultat vers Jeedom. Démarrée à l'allumage, annulée à l'extinction.
  Backoff exponentiel 15 s → 300 s sur erreur, redémarrage automatique du démon
  après 5 erreurs consécutives (récupération des 403 CloudFront).
- Les paramètres lourds (settings, schedule) sont rafraîchis beaucoup moins souvent,
  pour ne pas saturer l'API ni entrer en collision avec l'application smartphone.

### Commandes gérées par le démon

`login` · `detect` · `dash` · `settings` · `schedule` ·
`CoffeeMachineChangeMode` · `CoffeeMachineSettingSteamBoilerEnabled` ·
`CoffeeMachineSettingCoffeeBoilerTargetTemperature` ·
`CoffeeMachineSettingSteamBoilerTargetTemperature` ·
`CoffeeMachinePreInfusionChangeMode` · `CoffeeMachinePreBrewingChangeMode` ·
`CoffeeMachinePreBrewingChangeTimes` · `CoffeeMachineBrewByWeightSettingDoses` ·
`CoffeeMachineBrewByWeightChangeMode` · `CoffeeMachineBackFlushStartCleaning` ·
`CoffeeMachineSettingSmartStandBy`

---

## Choix techniques

- **API cloud uniquement.** Pas de communication directe avec la machine. Le Bluetooth
  serait plus complexe à mettre en œuvre côté Jeedom, et l'API locale nécessite une clé
  spécifique difficile à obtenir. Le périmètre fonctionnel utile est couvert par le cloud.
- `resources/jee4lm5d/pylamarzocco/` est du **code tiers de référence** (client REST +
  STOMP-over-websocket). À traiter en lecture seule : toute modification est un patch
  de dépendance, pas de la logique plugin.
- `resources/jee4lm5d/exceptions.py` en revanche appartient au plugin.

---

## Développement

Le dépôt **est** la racine du plugin (structure attendue par Jeedom).

```
core/class/jee4lm5.class.php      eqLogic + cmd, dispatch widgets, toHtml()
core/php/jee4lm5d.php             callback HTTP du démon
core/ajax/jee4lm5.ajax.php        actions UI (login, sync)
core/template/dashboard/          widget HTML/CSS/JS
core/i18n/fr_FR.json              traductions
desktop/                          page de configuration eqLogic + modal login
resources/jee4lm5d/               démon Python + pylamarzocco
plugin_info/                      info.json, packages.json, install.php
data/                             credential.json, installation_key.json (runtime)
```

Pas de suite de tests automatisée. Boucle de validation manuelle :

1. Éditer PHP / Python.
2. Depuis l'UI Jeedom : redémarrer le démon.
3. Suivre les logs : `jee4lm5d` (stdout/stderr du démon) et le log plugin Jeedom
   (sorties `log::add` côté PHP).

---

## Crédits

- API La Marzocco : [zweckj/pylamarzocco](https://github.com/zweckj/pylamarzocco) (projet Home Assistant)
- Démon inspiré de : [Mips2648/jeedom-aiodemo](https://github.com/Mips2648/jeedom-aiodemo)
- Support mDNS : [Chris Ridings](https://www.chrisridings.com)
