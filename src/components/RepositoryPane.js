import React from 'react';
import { useState, useEffect } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { DataGrid } from '@mui/x-data-grid';
import { Button } from '@wordpress/components';
import { darken, lighten, styled } from '@mui/material/styles';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import CloudDownloadIcon from '@mui/icons-material/CloudDownload';
import DifferenceIcon from '@mui/icons-material/Difference';
import DoneIcon from '@mui/icons-material/Done';
import BackupIcon from '@mui/icons-material/Backup';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { __ } from '@wordpress/i18n';
import PropTypes from 'prop-types';

// Demo
import {
  renderStatus,
} from './status';
import {
  randomColor,
  randomEmail,
  randomInt,
  randomName,
  randomArrayItem,
  random,
} from '@mui/x-data-grid-generator';


const getBackgroundColor = (color, theme, coefficient) => ({
  backgroundColor: darken(color, coefficient),
  ...theme.applyStyles('light', {
    backgroundColor: lighten(color, coefficient),
  }),
});

const StyledDataGrid = styled(DataGrid)(({ theme }) => ({
  '& .super-app-theme--notlocal': {
    ...getBackgroundColor(theme.palette.info.main, theme, 0.7),
    '&:hover': {
      ...getBackgroundColor(theme.palette.info.main, theme, 0.6),
    },
    '&.Mui-selected': {
      ...getBackgroundColor(theme.palette.info.main, theme, 0.5),
      '&:hover': {
        ...getBackgroundColor(theme.palette.info.main, theme, 0.4),
      },
    },
  },
  '& .super-app-theme--identical': {
    ...getBackgroundColor(theme.palette.success.main, theme, 0.7),
    '&:hover': {
      ...getBackgroundColor(theme.palette.success.main, theme, 0.6),
    },
    '&.Mui-selected': {
      ...getBackgroundColor(theme.palette.success.main, theme, 0.5),
      '&:hover': {
        ...getBackgroundColor(theme.palette.success.main, theme, 0.4),
      },
    },
  },
  '& .super-app-theme--notremote': {
    ...getBackgroundColor(theme.palette.warning.main, theme, 0.7),
    '&:hover': {
      ...getBackgroundColor(theme.palette.warning.main, theme, 0.6),
    },
    '&.Mui-selected': {
      ...getBackgroundColor(theme.palette.warning.main, theme, 0.5),
      '&:hover': {
        ...getBackgroundColor(theme.palette.warning.main, theme, 0.4),
      },
    },
  },
  '& .super-app-theme--different': {
    ...getBackgroundColor(theme.palette.error.main, theme, 0.7),
    '&:hover': {
      ...getBackgroundColor(theme.palette.error.main, theme, 0.6),
    },
    '&.Mui-selected': {
      ...getBackgroundColor(theme.palette.error.main, theme, 0.5),
      '&:hover': {
        ...getBackgroundColor(theme.palette.error.main, theme, 0.4),
      },
    },
  },
}));

const RepositoryPane = (props) => {
	const { setTab, setCurPost, setCurPostType } = props;
	const {createSuccessNotice, createErrorNotice} = useDispatch( noticesStore );
	const [repository, setRepository] = useState([]);
	const [devices, setDevices] = React.useState(() => ['notremote', 'notlocal', 'different']);

const columns = [
  {
    field: 'postType',
    headerName: 'Post type',
    width: 110,
    editable: false,
  },
  {
    field: 'id',
    headerName: 'Post name',
    width: 140,
    editable: false,
  },
  {
    field: 'status',
    headerName: 'Status',
    renderCell: renderStatus,
    width: 140,
    editable: false,
  },
  {
    field: 'localChecksum',
    headerName: 'Local checksum',
    width: 110,
    editable: false,
  },
  {
    field: 'remoteChecksum',
    headerName: 'Remote checksum',
    width: 110,
    editable: false,
  },
  {
    field: "action",
    headerName: "Action",
    width: 130,
    sortable: false,
    renderCell: (params) => {
      const onClickPush = (e) => {
        const currentRow = params.row;
        apiFetch({
          path: '/pushpull/v1/push',
          method: 'POST',
          data: { postname: currentRow.id, posttype: currentRow.postType },
		}).then((data) => {
			createSuccessNotice(__('Post '+currentRow.id+' pushed successfully.'), {
				isDismissible: true,
			});
			getRepoData();
		}).catch((error) => {
			createErrorNotice(__('Error pushing post '+currentRow.id+': '+error.message), {
				isDismissible: true,
			});
		});
      };

      const onClickDiff = (e) => {
        const currentRow = params.row;
        setTab('diff');
        setCurPostType(currentRow.postType);
        setCurPost(currentRow.id);
      };

      const onClickPull = (e) => {
        const currentRow = params.row;
        apiFetch({
          path: '/pushpull/v1/pull',
          method: 'POST',
          data: { postname: currentRow.id, posttype: currentRow.postType },
		}).then((data) => {
			createSuccessNotice(__('Post pulled successfully.'), {
				isDismissible: true,
			});
			getRepoData();
		}).catch((error) => {
			createErrorNotice(__('Error pulling post '+currentRow.id+': '+error.message), {
				isDismissible: true,
			});
		});
      };

      const onClickDelete = (e) => {
        const currentRow = params.row;
        apiFetch({
          path: '/pushpull/v1/delete',
          method: 'DELETE',
          data: { postname: currentRow.id, posttype: currentRow.postType },
		}).then((data) => {
			createSuccessNotice(__('Post deleted successfully.'), {
				isDismissible: true,
			});
			getRepoData();
		}).catch((error) => {
			createErrorNotice(__('Error deleting post '+currentRow.id+': '+error.message), {
				isDismissible: true,
			});
		});
      };

      //console.log(params);
      if (params.row.status === 'identical') {
        // Nothing to do, files are identical
        return (<></>);
      } else if (params.row.status === 'notlocal') {
        // File can be pulled
        return (
          <>
            <Button variant="outlined" color="warning" size="small" onClick={onClickPull}>Pull</Button>
            <Button variant="outlined" color="error" size="small" onClick={onClickDelete}>Delete</Button>
          </>
        );
      } else if (params.row.status === 'notremote') {
        // File can be pushed
        return (
          <>
            <Button variant="outlined" color="error" size="small" onClick={onClickPush}>Push</Button>
          </>
        );
      }
      // Files are not identical, we can either push or pull
      return (
        <>
          <Button variant="outlined" color="warning" size="small" onClick={onClickPull}>Pull</Button>
          <Button variant="outlined" color="error" size="small" onClick={onClickPush}>Push</Button>
          <Button variant="outlined" color="error" size="small" onClick={onClickDiff}>Diff</Button>
        </>
      );
    }
  },
];

	const getRepoData = () => {
		apiFetch({
			path: '/pushpull/v1/repo/local',
		}).then((data) => {
			setRepository(JSON.parse(data));
		}).catch((error) => {
			console.error(error);
		});
	}

	useEffect( () => {
		getRepoData();
	}, [] );

	const handleDevices = (event, newDevices) => {
		if (newDevices.length) {
			setDevices(newDevices);
		}
	};

	return (
	<>
		<ToggleButtonGroup
			value={devices}
			onChange={handleDevices}
			aria-label="device"
		>
			<ToggleButton value="notremote" aria-label="notremote">
				<BackupIcon />
			</ToggleButton>
			<ToggleButton value="notlocal" aria-label="notlocal">
				<CloudDownloadIcon />
			</ToggleButton>
			<ToggleButton value="identical" aria-label="identical">
				<DoneIcon />
			</ToggleButton>
			<ToggleButton value="different" aria-label="different">
				<DifferenceIcon />
			</ToggleButton>
		</ToggleButtonGroup>
		<StyledDataGrid
			rows={repository}
			columns={columns}
			filterModel={{
				items: [{ field: 'status', operator: 'isAnyOf', value: devices }],
			}}
			initialState={{
				pagination: {
					paginationModel: {
						pageSize: 50,
					},
				},
			}}
			pageSizeOptions={[5, 25, 50, 100]}
			checkboxSelection
			disableRowSelectionOnClick
			getRowClassName={(params) => `super-app-theme--${params.row.status}`}
		/>
	</>);
}

RepositoryPane.propTypes = {
  setTab: PropTypes.func.isRequired,
  setCurPost: PropTypes.func.isRequired,
  setCurPostType: PropTypes.func.isRequired,
};

export default RepositoryPane;
