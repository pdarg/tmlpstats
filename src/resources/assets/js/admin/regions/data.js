import { objectAssign } from '../../reusable/ponyfill'
import SimpleReduxLoader from '../../reusable/redux_loader/simple'
import FormReduxLoader from '../../reusable/redux_loader/rrf'
import { LoadingMultiState } from '../../reusable/reducers'
import { Schema, arrayOf } from 'normalizr'


/* DATA RELATIONS SCHEMA (normalizr) */

const quarterSchema = new Schema('quarters')
const centerSchema = new Schema('centers', {idAttribute: 'abbreviation'})
export const regionSchema = new Schema('regions')
regionSchema.define({
    centers: arrayOf(centerSchema),
    currentQuarter: quarterSchema,
    quarters: arrayOf(quarterSchema)
})

/* DATA MANAGEMENT LOADERS */

export const regionsData = new SimpleReduxLoader({
    prefix: 'admin/regions',
    loader: ({region}, {extra: {Api}}) => {
        return Api.Admin.Region.getRegion({region, lookups: ['centers']})
    },
    transformData: (data) => {
        if (data.region && data.centers) {
            data = objectAssign({}, data.region, data, {quarter: null})
        }
        if (data.centers) {
            data.centers = data.centers.map((x) => {
                return objectAssign(x, {abbreviation: x.abbreviation.toLowerCase()})
            })
        }
        return data
    }
})

export const centersData = new SimpleReduxLoader({
    prefix: 'admin/centers'
})


export const scoreboardLockData = new FormReduxLoader({
    prefix: 'admin/scoreboardLock',
    model: 'admin.regions.scoreboardLock.data',
    loader: (data, {extra: {Api}}) => {
        const {center, quarter} = data
        return Api.Scoreboard.getScoreboardLockQuarter({center, quarter})
    },
    extraLMS: ['saveState']
})

export const saveScoreboardLock = new LoadingMultiState('admin/scoreboardLock/saveState')
