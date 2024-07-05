<?php

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - {{Accès non autorisé}}', __FILE__));
    }
    log::add(__CLASS__, 'debug', ' action request =  '.init('action'));

    if (init('action') == 'autoDEL_eq') {
        $eqLogic = jee4lm::byId(init('id'));
        if (!is_object($eqLogic)) {
            throw new Exception(__('jee4lm eqLogic non trouvé : ', __FILE__) . init('id'));
        }
        foreach ($eqLogic->getCmd() as $cmd) {
            $cmd->remove();
            $cmd->save();
        }
        ajax::success();
    }

    throw new Exception(__('{{Aucune méthode correspondante à}} : ', __FILE__) . init('action'));
    /*     * *********Catch exeption*************** */
} catch (Exception $e) {
    if (version_compare(jeedom::version(), '4.4', '>=')) {
        ajax::error(displayException($e), $e->getCode());
    } else {
        ajax::error(displayExeption($e), $e->getCode());
    }
}
