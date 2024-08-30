<?php

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - {{Accès non autorisé}}', __FILE__));
    }
    log::add('jee4lm', 'debug', ' action request =  ' . init('action'));
    ajax::init();

    if (init('action') == 'login') {
        if (jee4lm::login(init('username'),init('password')))
            ajax::success();
        else
           throw new Exception(__('informations de connexion incorrectes', __FILE__));
    }

    if (init('action') == 'sync') {
        if (jee4lm::detect())
            ajax::success();
        else
            throw new Exception(__("la détection ne peut se faire qu'une fois la connexion réussie", __FILE__));
     }
      
    
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

    throw new Exception(__('Aucune méthode correspondant à : ', __FILE__) . init('action'));
} catch (Exception $e) {
    if (version_compare(jeedom::version(), '4.4', '>=')) {
        ajax::error(displayException($e), $e->getCode());
    } else {
        ajax::error(displayException($e), $e->getCode());
    }
}
