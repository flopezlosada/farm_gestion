/*
 * Welcome to your app's main JavaScript file!
 *
 * We recommend including the built version of this JavaScript file
 * (and its CSS file) in your base layout (base.html.twig).
 */

// any CSS you require will output into a single css file (app.css in this case)
require('../css/app.css');
require('../css/csa/index.css');

// Enhancer de <select> nativos → dropdown con estética csa (opt-in por
// [data-csa-dropdowns]). Ver assets/js/csa-dropdown.js.
require('./csa-dropdown.js');

// Pestañas para partir una ficha larga (opt-in por [data-csa-tabs]). Sin JS
// los paneles se ven todos. Ver assets/js/csa-tabs.js.
require('./csa-tabs.js');

// Avisos de "falta esto" en castellano y dentro del diseño, en lugar del globo
// del navegador (opt-in por [data-csa-validate]). Ver assets/js/csa-validate.js.
require('./csa-validate.js');

// Suma en vivo de las horas que se van a imputar al cerrar una tarea de
// voluntariado (opt-in por [data-vol-total]). Ver assets/js/vol-close-total.js.
require('./vol-close-total.js');

// Alta y baja de los avisos push (opt-in por [data-push-toggle]). No pide
// permiso hasta que alguien pulsa: un permiso denegado no se puede volver a
// pedir. Ver assets/js/push.js.
require('./push.js');

// Need jQuery? Install it with "yarn add jquery", then uncomment to require it.
// const $ = require('jquery');
