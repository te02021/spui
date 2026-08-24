/**
 * Entrypoint de assets de SPUI.
 *
 * Deliberadamente vacío: SPUI carga todo lo suyo con asset() desde
 * base.html.twig (Bootstrap por CDN, unraf-estilos.min.css, spui/css/style.css
 * y los scripts clásicos de spui/js/core/). No usa módulos ES.
 *
 * Antes esto era `import 'Shared';`, que arrastraba 1642 imports del monolito
 * (1539 SVG, 26 CSS, 24 JS) en cada página del CMS. Nada de eso hacía falta:
 *
 *  - Los SVG no necesitan importarse: AssetMapper los descubre por ruta
 *    (assets/ está en asset_mapper.paths), así que asset() los resuelve igual.
 *  - SPUI no usa jQuery ni DataTables (Shared los traía duplicados, en js/ y jsold/).
 *  - unraf-ui/main.js es el script de demo del UI kit (galería de iconos, modal
 *    de prueba, tabs). SPUI no usa ninguno de sus ganchos, y encima registraba un
 *    segundo listener sobre #btnMenuToggle que spui/js/core/sidebar.js tiene que
 *    neutralizar clonando el nodo.
 *  - Los CSS legacy (generic/base.css, table_style.css, css.old/…) definen reglas
 *    globales que compiten con Bootstrap 5 y unraf-ui sobre los mismos tags.
 *    Uno de ellos, styles/app.css, pinta el body de skyblue.
 *
 * assets/Shared.js NO se tocó: lo comparten sistemasat, viaticos y sgp.
 * Si en el futuro SPUI necesita algo del monolito, importalo puntualmente acá.
 */
