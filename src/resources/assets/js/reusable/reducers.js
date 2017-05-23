import { objectAssign } from './ponyfill'

const loadStates = {
    new: {state: 'new', loaded: false, available: true},
    loading: {state: 'loading', loaded: false, available: false},
    loaded: {state: 'loaded', loaded: true, available: true},
    failed: {state: 'failed', loaded: false, failed: true, available: false}
}

export function loadingMultiState(actionType) {
    return function(state = loadStates['new'], action){
        if (action.type == actionType) {
            // toUpperase check proves this is a string
            if (action.payload.toUpperCase) {
                return loadStates[action.payload]
            } else {
                return action.payload
            }
        }
        return state
    }
}

export class LoadingMultiState {
    static states = loadStates

    constructor(actionType) {
        this.actionType = actionType
    }

    actionCreator() {
        const actionType = this.actionType
        return (newState, extra) => {
            if (newState.error) {
                newState = objectAssign({}, loadStates.failed, newState)
            } else if (extra) {
                newState = objectAssign({}, loadStates[newState], extra)
            }
            return {type: actionType, payload: newState}
        }
    }

    reducer() {
        return loadingMultiState(this.actionType)
    }
}

const REPLACE_MESSAGES = 'messageManager/replace'
const REPLACE_ALL_MESSAGES = 'messageManager/reset'

export class MessageManager {
    constructor(namespace) {
        this.namespace = namespace
    }

    reducer() {
        return (state={}, action) => {
            if (action.payload && action.payload.namespace && action.payload.namespace == this.namespace) {
                switch (action.type) {
                case REPLACE_MESSAGES:
                    return objectAssign({}, state, action.payload.messages)
                case REPLACE_ALL_MESSAGES:
                    return action.payload.messages
                }
            }
            return state
        }
    }

    replaceAll(messages, namespace) {
        return {type: REPLACE_ALL_MESSAGES, payload: {messages, namespace: namespace || this.namespace}}
    }

    replaceMany(messageMap) {
        return {type: REPLACE_MESSAGES, payload: {messages: messageMap, namespace: this.namespace}}
    }

    replace(pk, messages) {
        return this.replaceMany({[pk]: messages})
    }

    reset() {
        return this.replaceAll({})
    }
}

const INLINE_BULK_DEFAULT = {ts: 0, changed: {}, working: null}

const IB_MARK = 'mark'
const IB_BEGIN_WORK = 'begin_work'
const IB_END_WORK = 'end_work'
const IB_CLEAR_WORK = 'clear_work'

/** Tracks bulk update work to some resource with a primary key. The idea is that we'd be live updating something. */
export class InlineBulkWork {
    constructor(prefix) {
        this.actionType = prefix
    }

    mark(pk) {
        return {type: this.actionType, payload:{job: IB_MARK, pk}}
    }

    beginWork() {
        return {type: this.actionType, payload:{job: IB_BEGIN_WORK}}
    }

    endWork() {
        return {type: this.actionType, payload:{job: IB_END_WORK}}
    }

    clearWork() {
        return {type: this.actionType, payload:{job: IB_CLEAR_WORK}}
    }

    reducer() {
        return (state=INLINE_BULK_DEFAULT, action) => {
            if (action.type == this.actionType) {
                const ts = state.ts + 1 // TS is an atomic incrementing timestamp, we can use this to see what happened 'after' other things.
                var { changed, working } = state
                const { job, pk } = action.payload
                switch (job) {
                case IB_MARK:
                    if (!changed[pk] || (working && working[pk])) {
                        changed = objectAssign({}, changed, {[pk]: ts})
                        return {ts, working, changed}
                    }
                    break
                case IB_BEGIN_WORK:
                    // Cool trick: set the currently working as the ones who have changed at this point, and we get the answer for free
                    return {ts, working: changed, changed}
                case IB_END_WORK:
                    // When work ends, we only want to clear changed items which have not changed yet again
                    changed = objectAssign({}, changed)
                    for (var k in working) {
                        if (changed[k] <= working[k]) {
                            delete changed[k]
                        }
                    }
                    return {ts, changed, working: null}
                }
            }
            return state
        }
    }
}
