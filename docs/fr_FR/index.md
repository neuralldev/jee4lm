# Plugin jee4lm5

Ce plugin permet de piloter une **La Marzocco Linea Mini** depuis **Jeedom 4.6**, via le cloud La Marzocco. Il supporte également la balance connectée **Acaia Lunar** pour le Brew By Weight (BBW).

- Dépôt : [github.com/neuralldev/jee4lm](https://github.com/neuralldev/jee4lm)
- Changelog : [voir le changelog](https://github.com/neuralldev/jee4lm/blob/dev/docs/fr_FR/changelog.md)

---

## Prérequis

- Jeedom 4.6 ou supérieur
- Un compte La Marzocco (application mobile LM)
- Une Linea Mini connectée au cloud La Marzocco
- Python 3.11+ (installé automatiquement par Jeedom sur Debian 12)

---

## Installation

1. Installez le plugin depuis le Market Jeedom (ou en déposant le dossier dans `/var/www/html/plugins/`)
2. Activez le plugin
3. Installez les dépendances (bouton **Installer les dépendances** sur la page du plugin) — installe `jeedomdaemon`, `bleak`, `aiohttp`, `mashumaro` et leurs dépendances via pip3
4. Démarrez le démon (bouton **Démarrer le démon**)

---

## Configuration

### Connexion au cloud La Marzocco

1. Sur la page de configuration du plugin (**Configuration** → **Plugin** → **jee4lm5**), cliquez sur **Se connecter**
2. Saisissez vos identifiants de l'application La Marzocco (email + mot de passe)
3. Cliquez **Valider** — le démon redémarre et s'authentifie auprès du cloud LM

> Les identifiants sont stockés localement dans `data/credential.json`. Ils ne transitent que vers les serveurs La Marzocco.

### Détection de la machine

1. Cliquez sur **Détecter mes équipements** depuis la page de configuration du plugin
2. La machine apparaît automatiquement dans la liste des équipements Jeedom
3. Positionnez-la dans un objet, rendez-la visible, sauvegardez

> La première réception du dashboard crée automatiquement toutes les commandes.

---

## Fonctionnalités

### Allumage / Extinction

Le bouton power central du widget allume ou éteint la machine (mode `BrewingMode` / `StandBy`). L'anneau SVG anime la progression de chauffe.

### Températures chaudières

- **Café** : consigne et température actuelle, statut (Off / En chauffe / Prêt)
- **Vapeur** : activation ON/OFF, consigne (si modèle avec vapeur réglable)
- Les sliders de réglage sont accessibles via la section **Réglages** (en bas du widget)

### Prétrempage & Préinfusion

- **Prétrempage** (PreBrewing) : active une phase de mouillage avant l'extraction. Configuration : durée mouillage et durée pause (sliders en secondes)
- **Préinfusion** (PreInfusion) : disponible uniquement sur machine plombée au réseau d'eau

### Brew By Weight — Acaia Lunar

Si une balance Acaia Lunar est détectée sur la station café :
- Affichage du statut de connexion et du niveau de batterie
- Sélection du mode (Dose 1 / Dose 2 / Continu)
- Réglage des doses A et B (sliders en grammes)

### Smart Standby

- Activation du réveil automatique à une heure programmée
- Configuration de la durée de veille et du type de déclencheur : **Dernier café** ou **Allumage**

### Backflush

Démarrage du cycle de rétro-lavage depuis le widget (section Réglages).

---

## Données actualisées

| Donnée | Source | Fréquence |
|---|---|---|
| Dashboard (températures, états) | Boucle `_dash_loop` démon | 15s (machine ON) |
| Dashboard (cron PHP) | Cron Jeedom | 1 min |
| Réglages (firmware, plombé) | Cron Jeedom | 2 min |
| Planning (smart standby) | Cron Jeedom | 2 min |

Le cron PHP s'arrête automatiquement entre 22h et 6h.

---

## Dépannage

### Le démon ne démarre pas

- Vérifiez que les dépendances sont installées (page plugin → **Dépendances**)
- Consultez le log `jee4lm5d` (Administration → Logs)
- Vérifiez que le port `50044` est libre : `fuser 50044/tcp`

### Erreur 403 après un certain temps

Le token d'accès La Marzocco expire. Le démon récupère automatiquement : invalidation du token → ré-authentification → reprise en 15–300s selon le nombre d'erreurs consécutives. Si le problème persiste, redémarrez manuellement le démon.

### La machine n'apparaît pas après la détection

- Vérifiez que le démon est démarré et que la connexion est active (logs)
- Relancez **Détecter mes équipements**
- Consultez les logs pour voir si la détection a retourné des données

### Les valeurs ne se mettent plus à jour

- Vérifiez que la machine est allumée (la boucle `_dash_loop` ne tourne que machine ON)
- Le cron PHP rafraîchit toutes les minutes même machine OFF
- Redémarrez le démon si nécessaire
