import { createSelector } from 'reselect'

const orderedAccountabilitiesSelector = (state) => state.submission.core.lookups.orderedAccountabilities
const accountabilitiesSelector = (state) => state.submission.core.lookups.accountabilities


/**
 * Create a selector which returns ordered accountabilities as a flat list.
 * @param  string context - if specified, filters accountabilities by their context.
 */
export function makeAccountabilitiesSelector(context) {
    return createSelector(
        [orderedAccountabilitiesSelector, accountabilitiesSelector],
        (ids, allAccountabilities) => {
            if (!ids) {
                return []
            }
            var accountabilities = ids.map(id => allAccountabilities[id])
            if (context) {
                accountabilities = accountabilities.filter(acc => acc.context == context)
            }
            return accountabilities
        }
    )
}


export const getLabelTeamMember = (item) => {
    const p = item.teamMember.person
    return p.firstName + ' ' + p.lastName
}

export function makeQuartersSelector(prop) {
    return createSelector(
        (core) => core.centerQuarters.data,
        (core) => core.lookups[prop],
        (cqData, ids) => {
            if (!ids) {
                return []
            }
            return ids.map(id => cqData[id])
        }
    )
}
