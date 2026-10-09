import { startStimulusApp } from '@symfony/stimulus-bundle';
import FeeTypeController from './controllers/fee_type_controller.js';
import TransferTypeController from './controllers/transfer_type_controller.js';

const app = startStimulusApp();
app.register('fee-type', FeeTypeController);
app.register('transfer-type', TransferTypeController);
