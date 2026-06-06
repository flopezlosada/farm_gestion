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

// Need jQuery? Install it with "yarn add jquery", then uncomment to require it.
// const $ = require('jquery');
