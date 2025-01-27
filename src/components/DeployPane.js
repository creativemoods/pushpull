import React from 'react';
import { useState, useEffect } from 'react';
import { Card, CardBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { DataGrid, useGridApiContext, GridRowModes, GridActionsCellItem, GridToolbarContainer, GridRowEditStopReasons } from '@mui/x-data-grid';
import Stack from '@mui/material/Stack';
import { Button as MUIButton, FormHelperText, TextareaAutosize, Grid2 } from '@mui/material';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import InputBase from '@mui/material/InputBase';
import Popper from '@mui/material/Popper';
import Paper from '@mui/material/Paper';
import AddIcon from '@mui/icons-material/Add';
import EditIcon from '@mui/icons-material/Edit';
import DeleteIcon from '@mui/icons-material/DeleteOutlined';
import SaveIcon from '@mui/icons-material/Save';
import CancelIcon from '@mui/icons-material/Close';
import { randomId } from '@mui/x-data-grid-generator';
import PlayCircleOutlineIcon from '@mui/icons-material/PlayCircleOutline';

// From https://mui.com/x/react-data-grid/recipes-editing/#multiline-editing
function EditTextarea(props) {
	const { id, field, value, colDef, hasFocus } = props;
	const [valueState, setValueState] = React.useState(value);
	const [anchorEl, setAnchorEl] = React.useState();
	const [inputRef, setInputRef] = React.useState(null);
	const apiRef = useGridApiContext();
  
	React.useLayoutEffect(() => {
	  if (hasFocus && inputRef) {
		inputRef.focus();
	  }
	}, [hasFocus, inputRef]);
  
	const handleRef = React.useCallback((el) => {
	  setAnchorEl(el);
	}, []);
  
	const handleChange = React.useCallback(
	  (event) => {
		const newValue = event.target.value;
		setValueState(newValue);
		apiRef.current.setEditCellValue(
		  { id, field, value: newValue, debounceMs: 200 },
		  event,
		);
	  },
	  [apiRef, field, id],
	);

	return (
	  <div style={{ position: 'relative', alignSelf: 'flex-start' }}>
		<div
		  ref={handleRef}
		  style={{
			height: 1,
			width: colDef.computedWidth,
			display: 'block',
			position: 'absolute',
			top: 0,
		  }}
		/>
		{anchorEl && (
		  <Popper open anchorEl={anchorEl} placement="bottom-start">
			<Paper elevation={1} sx={{ p: 1, minWidth: colDef.computedWidth }}>
			  <InputBase
				multiline
				rows={4}
				value={valueState}
				sx={{ textarea: { resize: 'both' }, width: '100%' }}
				onChange={handleChange}
				inputRef={(ref) => setInputRef(ref)}
			  />
			</Paper>
		  </Popper>
		)}
	  </div>
	);
}

function EditToolbar(props) {
	const { setDeployItems, setRowModesModel } = props;
	
	const handleClick = () => {
		const id = randomId();
		setDeployItems((oldRows) => [
			...oldRows,
			{ id, deployorder: 1, type: 'option_set', name: '', value: '', isNew: true },
		]);
		setRowModesModel((oldModel) => ({
			...oldModel,
			[id]: { mode: GridRowModes.Edit, fieldToFocus: 'name' },
		}));
	};

	return (
		<GridToolbarContainer>
		<MUIButton color="primary" startIcon={<AddIcon />} onClick={handleClick}>
		Add deployment item
		</MUIButton>
		</GridToolbarContainer>
	);
}

const DeployPane = (props) => {
	const {createSuccessNotice, createErrorNotice} = useDispatch( noticesStore );
	const [deployItems, setDeployItems] = useState([]);
	const [rowModesModel, setRowModesModel] = React.useState({});

	const textAreaValue = {
		type: 'string',
		renderEditCell: (params) => <EditTextarea {...params} />,
		headerName: 'Value',
		flex: 1,
		editable: true,
  	};

	const handleRowEditStop = (params, event) => {
		if (params.reason === GridRowEditStopReasons.rowFocusOut) {
		  event.defaultMuiPrevented = true;
		}
	};

	const handleEditClick = (id) => () => {
		setRowModesModel({ ...rowModesModel, [id]: { mode: GridRowModes.Edit } });
	};

	const handleSaveClick = (id) => () => {
		setRowModesModel({ ...rowModesModel, [id]: { mode: GridRowModes.View } });
	};

	const handleDeployClick = (id) => () => {
		apiFetch({
			path: '/pushpull/v1/deploy/deploy',
			method: 'POST',
			data: {'id': id},
			}).then((data) => {
				createSuccessNotice(__('Item successfully deployed.'), {
					isDismissible: true,
				});
			}).catch((error) => {
			createErrorNotice(__('Error deploying item with ID '+id+': '+error.message), {
				isDismissible: true,
			});
			console.error(error);
		});
	};

	const handleDeleteClick = (id) => () => {
		setDeployItems(deployItems.filter((row) => row.id !== id));
		apiFetch({
			path: '/pushpull/v1/deploy',
			method: 'DELETE',
			data: {'id': id},
			}).then((data) => {
				createSuccessNotice(__('Deployment item deleted.'), {
					isDismissible: true,
				});
			}).catch((error) => {
			createErrorNotice(__('Error deleting deployment item with ID '+id+': '+error.message), {
				isDismissible: true,
			});
			console.error(error);
		});
	};
	
	const handleCancelClick = (id) => () => {
		setRowModesModel({
		  ...rowModesModel,
		  [id]: { mode: GridRowModes.View, ignoreModifications: true },
		});
	
		const editedRow = deployItems.find((row) => row.id === id);
		if (editedRow.isNew) {
		  setDeployItems(deployItems.filter((row) => row.id !== id));
		}
	};

	const processRowUpdate = (newRow) => {
		let updatedRow;
		if (newRow.isNew) {
			updatedRow = { ...newRow };
		} else {
			updatedRow = { ...newRow, isNew: false };
		}
		setDeployItems(deployItems.map((row) => (row.id === newRow.id ? updatedRow : row)));
		apiFetch({
			path: updatedRow.isNew ? '/pushpull/v1/deploy/create' : '/pushpull/v1/deploy',
			method: 'POST',
			data: updatedRow,
			}).then((data) => {
				createSuccessNotice(__('Deployment item '+(updatedRow.isNew ? 'created.' : 'updated.')), {
					isDismissible: true,
				});
				// If creation, we need to update the ID
				if (updatedRow.isNew) {
					updatedRow.id = data;
					setDeployItems(deployItems.map((row) => (row.id === newRow.id ? updatedRow : row)));
				}
			}).catch((error) => {
			createErrorNotice(__('Error '+(updatedRow.isNew ? 'creating' : 'updating')+' deployment item '+newRow.name+': '+error.message), {
				isDismissible: true,
			});
			console.error(error);
		});

		return updatedRow;
	};

	const handleRowModesModelChange = (newRowModesModel) => {
		setRowModesModel(newRowModesModel);
	};

	const columns = [
		{
		  field: 'id',
		},
		{
		  field: 'deployorder',
		  headerName: 'Order',
		  width: 60,
		  type: 'number',
		  editable: true,
		  cellClassName: 'font-tabular-nums',
		},
		{
		  field: 'type',
		  headerName: 'Type',
		  width: 200,
		  editable: true,
		  type: 'singleSelect',
//		  valueOptions: ['option_set', 'option_add', 'option_merge', 'custom', 'lang_add', 'rest_request', 'folder_create', 'category_create', 'pushpull_pull', 'pushpull_pullall', 'menu_create', 'row_insert', 'rewrite_rules_flush', 'email_send'],
		  // Warning max 20 chars
		  valueOptions: ['option_set', 'option_setidfromname'],
	    },
		{
		  field: 'name',
		  headerName: 'Name',
		  width: 180,
		  type: 'string',
		  editable: true,
		},
		{
			field: 'value',
			...textAreaValue,
		},
		{
			field: 'actions',
			type: 'actions',
			headerName: 'Actions',
			width: 120,
			cellClassName: 'actions',
			getActions: ({ id }) => {
			  const isInEditMode = rowModesModel[id]?.mode === GridRowModes.Edit;

			  if (isInEditMode) {
				return [
				  <GridActionsCellItem
					icon={<SaveIcon />}
					label="Save"
					sx={{
					  color: 'primary.main',
					}}
					onClick={handleSaveClick(id)}
				  />,
				  <GridActionsCellItem
					icon={<CancelIcon />}
					label="Cancel"
					className="textPrimary"
					onClick={handleCancelClick(id)}
					color="inherit"
				  />,
				];
			  }

			  return [
				<GridActionsCellItem
				  icon={<EditIcon />}
				  label="Edit"
				  className="textPrimary"
				  onClick={handleEditClick(id)}
				  color="inherit"
				/>,
				<GridActionsCellItem
				  icon={<DeleteIcon />}
				  label="Delete"
				  onClick={handleDeleteClick(id)}
				  color="inherit"
				/>,
				<GridActionsCellItem
				  icon={<PlayCircleOutlineIcon />}
				  label="Deploy"
				  onClick={handleDeployClick(id)}
				  color="inherit"
				/>,
			  ];
			},
	    },
	];
	
	useEffect( () => {
		apiFetch({
			path: '/pushpull/v1/deploy',
		}).then((data) => {
			setDeployItems(data);
		}).catch((error) => {
			console.error(error);
		});
	}, [] );

	return (
		<Card>
			<CardBody>
				<Grid2 size={6}>
					<DataGrid
					rows={deployItems}
					columns={columns}
					editMode="row"
					rowModesModel={rowModesModel}
					onRowModesModelChange={handleRowModesModelChange}
					onRowEditStop={handleRowEditStop}
					processRowUpdate={processRowUpdate}
					getRowId={(row) => row.id}
					initialState={{
						columns: {
							columnVisibilityModel: {
								id: false,
							},
						},
						pagination: {
							paginationModel: {
								pageSize: 50,
							},
						},
					}}
					pageSizeOptions={[5, 25, 50, 100]}
					slots={{ toolbar: EditToolbar }}
					slotProps={{
						toolbar: { setDeployItems, setRowModesModel },
					}}
					/>
				</Grid2>
			</CardBody>
		</Card>
	);
}

DeployPane.propTypes = {
};

export default DeployPane;
