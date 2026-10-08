import { startStimulusApp } from '@symfony/stimulus-bundle';
import FeeTypeController from './controllers/fee_type_controller.js';

const app = startStimulusApp();
app.register('fee-type', FeeTypeController);
