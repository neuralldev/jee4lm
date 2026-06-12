<?php
/**
 * AJAX handler for jee4lm5 plugin.
 * All daemon calls are fire-and-forget — success is returned immediately after dispatch.
 */
try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - {{Accès non autorisé}}', __FILE__));
    }

    // Jeedom 4.5+ sends JSON body instead of form POST
    $data = json_decode(file_get_contents('php://input'), true);
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $_POST[$key] = $value;
        }
    }

    $action = init('action');
    log::add('jee4lm5', 'debug', 'ajax action=' . $action);

    switch ($action) {

        case 'login':
            // Fire-and-forget to daemon — login() always returns '' (no sync result)
            jee4lm5::login(init('username'), init('password'));
            ajax::success();
            break;

        case 'sync':
            // Fire-and-forget to daemon — detect() returns void
            jee4lm5::detect();
            ajax::success();
            break;

        default:
            throw new Exception(__('Aucune méthode correspondant à : ', __FILE__) . $action);
    }

} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}