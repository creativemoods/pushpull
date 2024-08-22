import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import App from "./App";
import './style/main.scss';

domReady( () => {
    const root = createRoot(
        document.getElementById('pushpull')
    );

    root.render( <App /> );
} );
