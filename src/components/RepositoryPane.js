import React from 'react';
import { useState, useEffect } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { DataGrid } from '@mui/x-data-grid';
import { Button } from '@wordpress/components';
import { Button as MUIButton, Grid2, TextareaAutosize } from '@mui/material';
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
import { renderStatus } from './status';
import ReactDiffViewer from 'react-diff-viewer-continued';

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
	const {createSuccessNotice, createErrorNotice} = useDispatch( noticesStore );
	const [repository, setRepository] = useState([]);
	const [statuses, setStatuses] = React.useState(() => ['notremote', 'notlocal', 'different']);
  const [oldCode, setOldCode] = useState("");
  const [newCode, setNewCode] = useState("");
  const [rowSelectionModel, setRowSelectionModel] = useState([]);
  const [lastSelected, setLastSelected] = useState(null);
  const [direction, setDirection] = useState('localtoremote');
  const [commitMessage, setcommitMessage] = useState('');

  const columns = [
    {
      field: 'postType',
      headerName: 'Post type',
      width: 110,
      editable: false,
      valueFormatter: (v) => { return v.includes('#') ? v.split('#')[1]+' ('+v.split('#')[0]+')' : v },
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
          push(currentRow.id, currentRow.postType);
        };

        const onClickPull = (e) => {
          const currentRow = params.row;
          pull(currentRow.id, currentRow.postType);
        };

        const onClickDeleteLocal = (e) => {
          const currentRow = params.row;
          deletelocal(currentRow.id, currentRow.postType);
        };

        const onClickDeleteRemote = (e) => {
          const currentRow = params.row;
          deleteremote(currentRow.id, currentRow.postType);
        };

        if (params.row.status === 'identical') {
          // Nothing to do, files are identical
          return (<></>);
        } else if (params.row.status === 'notlocal') {
          if (direction === 'localtoremote') {
            return (
              <>
                <Button variant="outlined" color="error" size="small" onClick={onClickDeleteRemote}>Delete remote</Button>
              </>
            );
          } else {
            return (
              <>
                <Button variant="outlined" color="warning" size="small" onClick={onClickPull}>Create locally</Button>
              </>
            );
          }
        } else if (params.row.status === 'notremote') {
          if (direction === 'localtoremote') {
            return (
              <Button variant="outlined" color="error" size="small" onClick={onClickPush}>Commit to repository</Button>
            );
          } else {
            return (
              <>
                <Button variant="outlined" color="error" size="small" onClick={onClickDeleteLocal}>Delete locally</Button>
              </>
            );
          }
        }
        // Files are not identical, we can either push or pull
        if (direction === 'localtoremote') {
          return (
            <>
              <Button variant="outlined" color="warning" size="small" onClick={onClickPush}>Commit changes to remote</Button>
            </>
          );
        } else {
          return (
            <>
              <Button variant="outlined" color="error" size="small" onClick={onClickPull}>Apply changes locally</Button>
            </>
          );
        }
      }
    },
  ];

	const getRepoData = () => {
		apiFetch({
			path: '/pushpull/v1/repo/diff',
		}).then((data) => {
			setRepository(JSON.parse(data));
		}).catch((error) => {
			console.error(error);
		});
	}

	useEffect( () => {
		getRepoData();
	}, [] );

	const handleStatuses = (event, newStatuses) => {
		if (newStatuses.length) {
			setStatuses(newStatuses);
		}
	};

  const handleDirection = (event, newDirection) => {
    if (newDirection) {
      setDirection(newDirection);
    }
  };

  const push = (postName, postType) => {
    apiFetch({
      path: '/pushpull/v1/push',
      method: 'POST',
      data: { postname: postName, posttype: postType },
    }).then((data) => {
      createSuccessNotice(__('Post '+postName+' pushed successfully.'), {
        isDismissible: true,
      });
      getRepoData();
    }).catch((error) => {
      createErrorNotice(__('Error pushing post '+postName+': '+error.message), {
        isDismissible: true,
      });
    });
  };

  const pull = (postName, postType) => {
    apiFetch({
      path: '/pushpull/v1/pull',
      method: 'POST',
      data: { postname: postName, posttype: postType },
    }).then((data) => {
      createSuccessNotice(__('Post pulled successfully.'), {
        isDismissible: true,
      });
      getRepoData();
    }).catch((error) => {
      createErrorNotice(__('Error pulling post '+postName+': '+error.message), {
        isDismissible: true,
      });
    });
  };

  const deletelocal = (postName, postType) => {
    apiFetch({
      path: '/pushpull/v1/deletelocal',
      method: 'POST',
      data: { postname: postName, posttype: postType },
    }).then((data) => {
      createSuccessNotice(__('Post successfully deleted locally.'), {
        isDismissible: true,
      });
      getRepoData();
    }).catch((error) => {
      createErrorNotice(__('Error locally deleting post '+postName+': '+error.message), {
        isDismissible: true,
      });
    });
  };

  const deleteremote = (postName, postType) => {
    apiFetch({
      path: '/pushpull/v1/deleteremote',
      method: 'POST',
      data: { postname: postName, posttype: postType },
    }).then((data) => {
      createSuccessNotice(__('Post successfully deleted remotely.'), {
        isDismissible: true,
      });
      getRepoData();
    }).catch((error) => {
      createErrorNotice(__('Error remotely deleting post '+postName+': '+error.message), {
        isDismissible: true,
      });
    });
  };

  const onClickCommit = (event) => {
    const selectedRows = rowSelectionModel.map((id) => {
      const rowId = id.substring(0, id.indexOf('#'));
      return repository.find((row) => row.id === rowId);
    });
    selectedRows.forEach((row) => {
      if (row.status === 'notlocal') {
        deleteremote(row.id, row.postType);
      } else if (row.status === 'notremote') {
        push(row.id, row.postType);
      } else if (row.status === 'different') {
        push(row.id, row.postType);
      }
    });
  };

  const onClickApply = (event) => {
    const selectedRows = rowSelectionModel.map((id) => {
      const rowId = id.substring(0, id.indexOf('#'));
      return repository.find((row) => row.id === rowId);
    });
    selectedRows.forEach((row) => {
      if (row.status === 'notremote') {
        deletelocal(row.id, row.postType);
      } else if (row.status === 'notlocal') {
        pull(row.id, row.postType);
      } else if (row.status === 'different') {
        pull(row.id, row.postType);
      }
    });
  };

  const onRowSelectionHandler = (newSelection) => {
    // Find the last selected row
    if (newSelection.length < rowSelectionModel.length) {
      setLastSelected(null);
    } else if (newSelection.length > rowSelectionModel.length) {
      const newlySelected = newSelection.filter(id => !rowSelectionModel.includes(id));
      if (newlySelected.length > 0) {
        setLastSelected(newlySelected[newlySelected.length - 1]); // Update the last selected row
      }
    }
    // Don't select identical rows
    newSelection = newSelection.filter((id) => {
      const row = repository.find((r) => r.id === id.substring(0, id.indexOf('#')));
      return row.status !== 'identical';
    });
    setRowSelectionModel(newSelection);
  };

	useEffect( () => {
    if (lastSelected) {
      const selectedRowData = repository.find((row) => row.id === lastSelected.substring(0, lastSelected.indexOf('#')));
      apiFetch({
        path: addQueryArgs('/pushpull/v1/diff', { 'post_name': selectedRowData.id, 'post_type': selectedRowData.postType } ),
      }).then((data) => {
        setOldCode(data['local']);
        setNewCode(data['remote']);
      }).catch((error) => {
        console.error(error);
      });
    } else {
      setOldCode("");
      setNewCode("");
    }
	}, [lastSelected] );

  return (
    <Grid2 container spacing={1}>
      <Grid2 size={3} display="flex" justifyContent="left">
        <ToggleButtonGroup
          value={direction}
          onChange={handleDirection}
          aria-label="direction"
          exclusive
        >
          <ToggleButton value="localtoremote" aria-label="local to remote">
            <BackupIcon fontSize="small" sx={{ mr: 1 }} />
            Commit to remote
          </ToggleButton>
          <ToggleButton value="remotetolocal" aria-label="remote to local">
            <CloudDownloadIcon fontSize="small" sx={{ mr: 1 }} />
            Apply locally
          </ToggleButton>
        </ToggleButtonGroup>
      </Grid2>
      <Grid2 size={3} display="flex" justifyContent="left">
        <ToggleButtonGroup
          value={statuses}
          onChange={handleStatuses}
          aria-label="status"
        >
          <ToggleButton value="notremote" aria-label="notremote">
            <BackupIcon sx={(theme) => ({ color: theme.applyStyles('light', {color: theme.palette.warning.main}) })}/>
          </ToggleButton>
          <ToggleButton value="notlocal" aria-label="notlocal">
            <CloudDownloadIcon sx={(theme) => ({ color: theme.applyStyles('light', {color: theme.palette.info.main}) })}/>
          </ToggleButton>
          <ToggleButton value="identical" aria-label="identical">
            <DoneIcon sx={(theme) => ({ color: theme.applyStyles('light', {color: theme.palette.success.main}) })}/>
          </ToggleButton>
          <ToggleButton value="different" aria-label="different">
            <DifferenceIcon sx={(theme) => ({ color: theme.applyStyles('light', {color: theme.palette.error.main}) })}/>
          </ToggleButton>
        </ToggleButtonGroup>
      </Grid2>
      <Grid2 size={6} display="flex" justifyContent="left">
        <MUIButton
          variant="contained"
          onClick={direction === 'localtoremote' ? onClickCommit : onClickApply}
          color={'primary'}
          startIcon={direction === 'localtoremote' ? <BackupIcon /> : <CloudDownloadIcon />}
          sx={{mr: 1}}
        >
          { __( direction === 'localtoremote' ? 'Commit changes' : 'Apply changes', 'pushpull' ) }
        </MUIButton>
      {direction === false/* TODO 'localtoremote'*/ && <TextareaAutosize
					aria-label={ __( 'Commit message', 'pushpull' ) }
					minRows={3}
					placeholder={ __( 'Commit message.', 'pushpull' ) }
					value={commitMessage}
					onChange={(e) => setcommitMessage(e.value)}
					style={{ width: "100%" }}
				/>}
      </Grid2>
      <Grid2 size={12}>
        <StyledDataGrid
          rows={repository}
          columns={columns}
          getRowId={(row) => row.id + '#' + row.localChecksum + row.remoteChecksum}
          filterModel={{
            items: [
              { field: 'status', operator: 'isAnyOf', value: statuses },
            ],
          }}
          initialState={{
            pagination: {
              paginationModel: {
                pageSize: 5,
              },
            },
          }}
          pageSizeOptions={[5, 25, 50, 100]}
          checkboxSelection
          onRowSelectionModelChange={onRowSelectionHandler}
          rowSelectionModel={rowSelectionModel}
          //isRowSelectable={(params) => params.row.status != 'identical'}
          getRowClassName={(params) => `super-app-theme--${params.row.status}`}
        />
      </Grid2>
      <Grid2 size={12}>
        <ReactDiffViewer
          oldValue={oldCode}
          newValue={newCode}
          splitView={true}
          extraLinesSurroundingDiff={0}
          leftTitle={"Local"}
          rightTitle={"Remote"}
        />
      </Grid2>
    </Grid2>
  );
}

export default RepositoryPane;
