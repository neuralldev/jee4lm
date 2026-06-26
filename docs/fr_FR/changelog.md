# Changelog — jee4lm5

>**IMPORTANT**
>
>S'il n'y a pas d'information sur la mise à jour, c'est que celle-ci concerne uniquement de la mise à jour de documentation, de traduction ou de texte.

---

# 26/06/2026

### Migration Jeedom 4.6 & audit qualité

- Migration déclarée vers Jeedom 4.6 (`info.json`)
- **Corrections critiques PHP**
  - `execute()` case `jee4lm_smartwakeup_on/off` : ajout des guards `is_object()` (fatal PHP si commandes absentes)
  - `updateDisplay()` : correction comparaison `$cmd != null` → `is_object()`
  - `backupExclude()` : correction chemin `resources/venv` → `resources/python_venv` (le venv était inclus dans les backups Jeedom)
  - `cron()` : encapsulation dans `try/catch` pour éviter les exceptions non catchées quand le démon est arrêté
  - `login.php` : `handleAjaxError()` supprimé → `jeedomUtils.showAlert()` (compatible Jeedom 4.6)
- **Récupération automatique des erreurs 403 CloudFront**
  - Sur 403 : invalidation du token (`_access_token = None`) → ré-authentification complète au prochain appel
  - Backoff exponentiel dans `_dash_loop` : 15s → 30s → 60s → 120s → 240s → max 300s
  - Après 5 erreurs consécutives : redémarrage automatique du démon
- **Réduction du polling**
  - Intervalle `_dash_loop` réduit de 5s à 15s
- **Accès sécurisé aux champs message Python** : `message["value"]` → `message.get("value", 0)`
- **Logs** : niveaux `info` rétrogradés en `debug` pour les traces démon, suppression du log par widget dans `RefreshThingDashboardInformation`

---

# 2026 (janv–mai)

### Support Brew By Weight (BBW) — Acaia Lunar

- Détection automatique de la balance Acaia Lunar via `coffee_station.accessories`
- Widget BBW : doses A/B, mode continu, état balance, niveau batterie
- Sliders de réglage des doses avec correction affichage IEEE 754 (ex. `34.199` → `34.2` pendant le drag)
- Correction alignement dose `bbwdoseA/bbwdoseB` entre PHP et démon

### Dashboard HTML personnalisé

- Template HTML/CSS/JS dédié via `toHtml()`
- Bouton power avec anneau SVG animé (progression chauffe)
- Sections conditionnelles : prétrempage / préinfusion, BBW, smart standby
- Mise à jour temps réel via événement `jeedom.cmd.update` sans rechargement du DOM
- Correction duplication HTML sur `cmd::save()` dans `RefreshThingDashboardInformation`

### Prétrempage & Préinfusion

- Mode prétrempage (PreBrewing) et préinfusion (PreInfusion)
- Sliders durée mouillage / pause mouillage
- Confirmation polling 2,5s / timeout 10s après commande

### Smart Standby

- Activation/désactivation et configuration durée + déclencheur (dernier café / allumage)
- Correction bug : constantes `"LastBrewing"` / `"PowerOn"` fixes (was `$_options` passé à tort)

### Architecture démon

- Boucle dashboard `_dash_loop` par machine (démarrage sur ON, arrêt sur OFF)
- Pattern optimiste + `_await_command_confirmed` (poll `get_dashboard()` toutes les 2,5s jusqu'à 10s)
- Mise à jour locale du modèle `_machine.py` après commande (évite l'état `Pending` serveur)
- `set_steam_target_temperature` : ajout `ModelCode.LINEA_MINI` au décorateur `@models_supported`

---

# 07/09/2024

- Mise au point initiale et suppression des messages de trace trop volumineux
