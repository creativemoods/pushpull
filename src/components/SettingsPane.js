import React from 'react';
import { useState, useEffect } from 'react';
import { Button, Card, CardBody, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

const SettingsPane = () => {
	const {createSuccessNotice} = useDispatch( noticesStore );
	const [host, setHost] = useState('');
	const [repository, setRepository] = useState('');
	const [oauthToken, setOauthToken] = useState('');

	useEffect( () => {
		apiFetch({
			path: '/pushpull/v1/settings',
		}).then((data) => {
			setHost(data['host']);
			setOauthToken(data['oauth-token']);
			setRepository(data['repository']);
		}).catch((error) => {
			console.error(error);
		});
	}, [] );

	const handleSubmit = (event) => {
		event.preventDefault();
		apiFetch({
			path: '/pushpull/v1/settings/',
			method: 'POST',
			data: {
				'host': host,
				'oauth-token': oauthToken,
				'repository': repository,
			},
		}).then((data) => {
			createSuccessNotice(__('Settings saved.'), {
				isDismissible: true,
			});
		}).catch((error) => {
			console.error(error);
		});
	};

	return (
            <form onSubmit={handleSubmit}>
	        <Card>
	            <CardBody>
                        <TextControl
                            label={ __( 'Git API', 'pushpull' ) }
                            help={ __( 'The Git API URL.', 'pushpull' ) }
			    value={host}
			    onChange={setHost}
                        />
                        <TextControl
                            label={ __( 'Project', 'pushpull' ) }
                            help={ __( 'The Git repository to commit to.', 'pushpull' ) }
			    value={repository}
			    onChange={setRepository}
                        />
                        <TextControl
                            label={ __( 'Oauth Token', 'pushpull' ) }
                            help={ __( 'A personal oauth token with public_repo scope.', 'pushpull' ) }
			    value={oauthToken}
			    onChange={setOauthToken}
                        />
			<Button variant="primary" type="submit">
				{ __( 'Save', 'pushpull' ) }
			</Button>
                    </CardBody>
	        </Card>
	    </form>
	);
}

export default SettingsPane;
