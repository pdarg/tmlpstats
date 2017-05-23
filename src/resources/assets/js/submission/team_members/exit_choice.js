const EC_ONTEAM = ''
const EC_XFER_OUT = 'xferOut'
const EC_WBO = 'wbo'
const EC_CTW = 'ctw'
const EC_WD = 'wd'

export const EXIT_CHOICES = [
    {key: EC_ONTEAM, label: 'On Team'},
    {key: EC_XFER_OUT, label: 'Transfer Out'},
    {key: EC_WBO, label: 'Well-Being Out'},
    {key: EC_CTW, label: 'Conversation to Withdraw'},
    {key: EC_WD, label: 'Withdrawn'}
]

export const EXIT_CHOICES_HELP = {
    xferOut: 'Choose this option to mark that a team has transferred to another team this quarter. Provide the team they are transferring to in the comment field.',
    wbo: 'Choosing this option indicates that this participant (a current team member) has a well-being issue and is taking time away from the team. 4 to 6 week maximum. Time longer than this must be approved by Jerry Baden.',
    ctw: 'Choose this option to indicate this current team member is in the conversation to withdraw from the team.  To actually be withdrawn the person must be approved by the Program Manager.',
    wd:  'Choose this option to indicate that this team member has withdrawn. You must select a withdraw reason below.'
}


/**
 * Given a teammember object, determine the appropriate exitChoice value.
 * @param  object teamMember
 * @return string Exit choice enum value.
 */
export function determineExitChoice(teamMember, _state) {
    if (teamMember.withdrawCode) {
        return EC_WD
    } else if (teamMember.wbo) {
        return EC_WBO
    } else if (teamMember.xferOut) {
        return EC_XFER_OUT
    } else if (teamMember.ctw) {
        return EC_CTW
    } else {
        return EC_ONTEAM
    }
}

/**
 * Given an exitChoice, determine what needs to be clobbered on the TeamMember
 * @param  string exitChoice [description]
 * @return {[type]}            [description]
 */
export function exitChoiceMerges(exitChoice) {
    var merges = {withdrawCode: null, xferOut: false, ctw: false, wbo: false, exitChoice}
    switch (exitChoice) {
    case EC_WD:
        delete merges.withdrawCode
        break
    case EC_WBO:
    case EC_XFER_OUT:
    case EC_CTW:
        // We can abuse the fact that the property name == the choice constant for these four
        merges[exitChoice] = true
        break
    }
    return merges
}
