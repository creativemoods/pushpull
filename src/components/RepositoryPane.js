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
      const onClick = (e) => {
        const currentRow = params.row;
        return alert(JSON.stringify(currentRow, null, 4));
      };
      const onClickDiff = (e) => {
        const currentRow = params.row;
        return alert(JSON.stringify(currentRow, null, 4));
      };

      //console.log(params);
      if (params.row.status === 'identical') {
        // Nothing to do, files are identical
        return (<></>);
      } else if (params.row.status === 'notlocal') {
        // File can be pulled
        return (
          <>
            <Button variant="outlined" color="warning" size="small" onClick={onClick}>Pull</Button>
          </>
        );
      } else if (params.row.status === 'notremote') {
        // File can be pushed
        return (
          <>
            <Button variant="outlined" color="error" size="small" onClick={onClick}>Push</Button>
          </>
        );
      }
      // Files are not identical, we can either push or pull
      return (
        <>
          <Button variant="outlined" color="warning" size="small" onClick={onClick}>Pull</Button>
          <Button variant="outlined" color="error" size="small" onClick={onClick}>Push</Button>
          <Button variant="outlined" color="error" size="small" onClick={onClickDiff}>Diff</Button>
        </>
      );
    }
  },
];

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

const RepositoryPane = () => {
	const [repository, setRepository] = useState([]);
	const [devices, setDevices] = React.useState(() => ['notremote', 'notlocal', 'different']);

	useEffect( () => {
		apiFetch({
			path: '/pushpull/v1/repo/local',
		}).then((data) => {
			setRepository(JSON.parse(data));
		}).catch((error) => {
			console.error(error);
		});
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
						pageSize: 25,
					},
				},
			}}
			pageSizeOptions={[5, 25, 100]}
			checkboxSelection
			disableRowSelectionOnClick
			getRowClassName={(params) => `super-app-theme--${params.row.status}`}
		/>
	</>);
}

export default RepositoryPane;
