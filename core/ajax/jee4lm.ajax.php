<?php
/**
 * 
 * This script handles AJAX requests for the JEE4LM application.
 * 
 * @throws Exception If an error occurs during the execution of the try block.
 */
try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - {{Accès non autorisé}}', __FILE__));
    }

    // necessary for jeedom 4.5 which does not use jquery anymore
    $data = json_decode(file_get_contents('php://input'), true);
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $_POST[$key] = $value;
        }
    }

    $action = init('action');
    log::add('jee4lm', 'debug', ' action request = (' . $action. ')');
    switch ($action) {
        case 'login':
            if (jee4lm::login(init('username'), init('password'))) {
                ajax::success();
            } else {
                throw new Exception(__('informations de connexion incorrectes', __FILE__));
            }
            break;

        case 'sync':
            if (jee4lm::detect()) {
                ajax::success();
            } else {
                throw new Exception(__("la détection ne peut se faire qu'une fois la connexion réussie", __FILE__));
            }
            break;

        case 'tcpdetect':
            if (jee4lm::tcpdetect()) {
                ajax::success();
            } else {
                throw new Exception(__("la détection de machine sur tcpip n'a pas trouvé de machines", __FILE__));
            }
            break;

        default:
            throw new Exception(__('Aucune méthode correspondant à : ', __FILE__) . $action);
    }
} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
