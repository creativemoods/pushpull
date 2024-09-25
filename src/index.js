import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import { StyledEngineProvider } from '@mui/material/styles';
import App from "./App";
import './style/main.scss';

domReady( () => {
    const root = createRoot(
        document.getElementById('pushpull')
    );

    root.render(
      <StyledEngineProvider injectFirst>
        <App />
      </StyledEngineProvider>
    );
} );
