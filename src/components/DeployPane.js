import React from 'react';
import { useState, useEffect } from 'react';
import { Button, Card, CardBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import Stack from '@mui/material/Stack';
import { Button as MUIButton, FormHelperText, TextareaAutosize } from '@mui/material';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

const DeployPane = (props) => {
	const {createSuccessNotice} = useDispatch( noticesStore );
	const [deployScript, setDeployScript] = useState('');

	useEffect( () => {
		apiFetch({
			path: '/pushpull/v1/deployscript',
		}).then((data) => {
			setDeployScript(data['deployscript']);
		}).catch((error) => {
			console.error(error);
		});
	}, [] );

	const handleDeploy = (event) => {
		apiFetch({
			path: '/pushpull/v1/deploy',
			method: 'POST',
			data: {
			},
			}).then((data) => {
				createSuccessNotice(__('Configuration and contents deployed.'), {
					isDismissible: true,
				});
			}).catch((error) => {
			console.error(error);
		});
	};

	const handleSetDeployScript = (event) => {
		setDeployScript(event.target.value);
	};

	const handleSubmit = (event) => {
		event.preventDefault();
		apiFetch({
			path: '/pushpull/v1/deployscript/',
			method: 'POST',
			data: {
				'deployscript': deployScript,
			},
		}).then((data) => {
			createSuccessNotice(__('Deploy script saved.'), {
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
				<TextareaAutosize
					aria-label={ __( 'Deploy script', 'pushpull' ) }
					minRows={3}
					placeholder={ __( 'Your PHP deployment script.', 'pushpull' ) }
					value={deployScript}
					onChange={handleSetDeployScript}
					style={{ width: "100%" }}
				/>
				<Stack direction="row" spacing={2}>
					<MUIButton
						variant="contained"
						onClick={handleDeploy}
						color='primary'
					>
						{ __( 'Deploy', 'pushpull' ) }
					</MUIButton>
					<FormHelperText>{__( 'Deploy configuration and contents.', 'pushpull' )}</FormHelperText>
				</Stack>
				<p>&nbsp;</p>
				<MUIButton variant="contained" color='primary' type="submit">
					{ __( 'Save', 'pushpull' ) }
				</MUIButton>
			</CardBody>
		</Card>
	</form>
	);
}

DeployPane.propTypes = {
};

export default DeployPane;
