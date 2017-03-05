//// THIS FILE IS AUTOMATICALLY GENERATED!
//// DO NOT EDIT BY HAND!

function apiCall(name, params, callback, errback) {
	var input = $.extend({method: name}, params)
    if (!callback) {
        callback = function(data, textStatus) {
            console.log(data);
        };
    }
    if (!errback) {
        errback = function(jqXHR, textStatus, errorThrown) {
            console.log(textStatus);
            console.log(errorThrown);
        };
    }
	return $.ajax({
		url: "/api",
		method: 'POST',
		contentType: "application/json",
		data: JSON.stringify(input)
	}).done(callback).fail(errback);
}

var Api = {};

Api.Admin = {
};
Api.Admin.Region = {

    /*
    get region and centers in region and some other info
    Parameters:
      region: Region
    */
    getRegion: function(params, callback, errback) {
        return apiCall('Admin.Region.getRegion', params, (callback || null), (errback || null));
    }
};
Api.Admin.Quarter = {

    /*
    Filter/list all quarters
    Parameters:
    */
    filter: function(params, callback, errback) {
        return apiCall('Admin.Quarter.filter', params, (callback || null), (errback || null));
    }
};
Api.Application = {

    /*
    Create new application
    Parameters:
      data: array
    */
    create: function(params, callback, errback) {
        return apiCall('Application.create', params, (callback || null), (errback || null));
    },

    /*
    List applications by center
    Parameters:
      center: Center
      reportingDate: date
      includeInProgress: bool
    */
    allForCenter: function(params, callback, errback) {
        return apiCall('Application.allForCenter', params, (callback || null), (errback || null));
    },

    /*
    Stash combined data for an application
    Parameters:
      center: Center
      reportingDate: date
      data: array
    */
    stash: function(params, callback, errback) {
        return apiCall('Application.stash', params, (callback || null), (errback || null));
    }
};
Api.Context = {

    /*
    Get the current center
    Parameters:
    */
    getCenter: function(params, callback, errback) {
        return apiCall('Context.getCenter', params, (callback || null), (errback || null));
    },

    /*
    Set the current center
    Parameters:
      center: Center
      permanent: bool
    */
    setCenter: function(params, callback, errback) {
        return apiCall('Context.setCenter', params, (callback || null), (errback || null));
    },

    /*
    Get a single setting value given a center
    Parameters:
      name: string
      center: Center
    */
    getSetting: function(params, callback, errback) {
        return apiCall('Context.getSetting', params, (callback || null), (errback || null));
    }
};
Api.Course = {

    /*
    Create new course
    Parameters:
      data: array
    */
    create: function(params, callback, errback) {
        return apiCall('Course.create', params, (callback || null), (errback || null));
    },

    /*
    List courses by center
    Parameters:
      center: Center
      reportingDate: date
      includeInProgress: bool
    */
    allForCenter: function(params, callback, errback) {
        return apiCall('Course.allForCenter', params, (callback || null), (errback || null));
    },

    /*
    Stash combined data for an course
    Parameters:
      center: Center
      reportingDate: date
      data: array
    */
    stash: function(params, callback, errback) {
        return apiCall('Course.stash', params, (callback || null), (errback || null));
    }
};
Api.GlobalReport = {

    /*
    Get ratings for all teams
    Parameters:
      globalReport: GlobalReport
      region: Region
    */
    getRating: function(params, callback, errback) {
        return apiCall('GlobalReport.getRating', params, (callback || null), (errback || null));
    },

    /*
    Get scoreboard for all weeks within a quarter
    Parameters:
      globalReport: GlobalReport
      region: Region
    */
    getQuarterScoreboard: function(params, callback, errback) {
        return apiCall('GlobalReport.getQuarterScoreboard', params, (callback || null), (errback || null));
    },

    /*
    Get scoreboard for a single week within a quarter
    Parameters:
      globalReport: GlobalReport
      region: Region
      futureDate: date
    */
    getWeekScoreboard: function(params, callback, errback) {
        return apiCall('GlobalReport.getWeekScoreboard', params, (callback || null), (errback || null));
    },

    /*
    Get scoreboard for a single week within a quarter by center
    Parameters:
      globalReport: GlobalReport
      region: Region
      options: array
    */
    getWeekScoreboardByCenter: function(params, callback, errback) {
        return apiCall('GlobalReport.getWeekScoreboardByCenter', params, (callback || null), (errback || null));
    },

    /*
    Get the list of incoming team members by center
    Parameters:
      globalReport: GlobalReport
      region: Region
      options: array
    */
    getApplicationsListByCenter: function(params, callback, errback) {
        return apiCall('GlobalReport.getApplicationsListByCenter', params, (callback || null), (errback || null));
    },

    /*
    Get the list of team members by center
    Parameters:
      globalReport: GlobalReport
      region: Region
      options: array
    */
    getClassListByCenter: function(params, callback, errback) {
        return apiCall('GlobalReport.getClassListByCenter', params, (callback || null), (errback || null));
    },

    /*
    Get the list of courses
    Parameters:
      globalReport: GlobalReport
      region: Region
    */
    getCourseList: function(params, callback, errback) {
        return apiCall('GlobalReport.getCourseList', params, (callback || null), (errback || null));
    },

    /*
    Get the global report page(s) named
    Parameters:
      globalReport: GlobalReport
      region: Region
      pages: array
    */
    getReportPages: function(params, callback, errback) {
        return apiCall('GlobalReport.getReportPages', params, (callback || null), (errback || null));
    },

    /*
    Get the global report page(s) named
    Parameters:
      region: Region
      reportingDate: date
      pages: array
    */
    getReportPagesByDate: function(params, callback, errback) {
        return apiCall('GlobalReport.getReportPagesByDate', params, (callback || null), (errback || null));
    }
};
Api.LiveScoreboard = {

    /*
    Get scores for a center
    Parameters:
      center: Center
    */
    getCurrentScores: function(params, callback, errback) {
        return apiCall('LiveScoreboard.getCurrentScores', params, (callback || null), (errback || null));
    },

    /*
    Set a single score
    Parameters:
      center: Center
      game: string
      type: string
      value: int
    */
    setScore: function(params, callback, errback) {
        return apiCall('LiveScoreboard.setScore', params, (callback || null), (errback || null));
    }
};
Api.LocalReport = {

    /*
    Get scoreboard for all weeks within a quarter
    Parameters:
      localReport: LocalReport
      options: array
    */
    getQuarterScoreboard: function(params, callback, errback) {
        return apiCall('LocalReport.getQuarterScoreboard', params, (callback || null), (errback || null));
    },

    /*
    Get scoreboard for a single week within a quarter
    Parameters:
      localReport: LocalReport
    */
    getWeekScoreboard: function(params, callback, errback) {
        return apiCall('LocalReport.getWeekScoreboard', params, (callback || null), (errback || null));
    },

    /*
    Get the list of incoming team members
    Parameters:
      localReport: LocalReport
      options: array
    */
    getApplicationsList: function(params, callback, errback) {
        return apiCall('LocalReport.getApplicationsList', params, (callback || null), (errback || null));
    },

    /*
    Get the list of all team members
    Parameters:
      localReport: LocalReport
    */
    getClassList: function(params, callback, errback) {
        return apiCall('LocalReport.getClassList', params, (callback || null), (errback || null));
    },

    /*
    Get the list of all team members, arranged by T1/T2 and by quarter
    Parameters:
      localReport: LocalReport
    */
    getClassListByQuarter: function(params, callback, errback) {
        return apiCall('LocalReport.getClassListByQuarter', params, (callback || null), (errback || null));
    },

    /*
    Get the list of courses
    Parameters:
      localReport: LocalReport
    */
    getCourseList: function(params, callback, errback) {
        return apiCall('LocalReport.getCourseList', params, (callback || null), (errback || null));
    },

    /*
    Center Quarter
    Parameters:
      center: Center
      quarter: Quarter
    */
    getCenterQuarter: function(params, callback, errback) {
        return apiCall('LocalReport.getCenterQuarter', params, (callback || null), (errback || null));
    },

    /*
    View options for report
    Parameters:
      center: Center
      reportingDate: date
    */
    reportViewOptions: function(params, callback, errback) {
        return apiCall('LocalReport.reportViewOptions', params, (callback || null), (errback || null));
    },

    /*
    Get report pages
    Parameters:
      center: Center
      reportingDate: date
      pages: array
    */
    getReportPages: function(params, callback, errback) {
        return apiCall('LocalReport.getReportPages', params, (callback || null), (errback || null));
    }
};
Api.Scoreboard = {

    /*
    Get scoreboard data for center
    Parameters:
      center: Center
      reportingDate: date
      includeInProgress: bool
    */
    allForCenter: function(params, callback, errback) {
        return apiCall('Scoreboard.allForCenter', params, (callback || null), (errback || null));
    },

    /*
    Save scoreboard data for week
    Parameters:
      center: Center
      reportingDate: date
      data: array
    */
    stash: function(params, callback, errback) {
        return apiCall('Scoreboard.stash', params, (callback || null), (errback || null));
    },

    /*
    TBD
    Parameters:
      center: Center
      quarter: Quarter
    */
    getScoreboardLockQuarter: function(params, callback, errback) {
        return apiCall('Scoreboard.getScoreboardLockQuarter', params, (callback || null), (errback || null));
    },

    /*
    TBD
    Parameters:
      center: Center
      quarter: Quarter
      data: array
    */
    setScoreboardLockQuarter: function(params, callback, errback) {
        return apiCall('Scoreboard.setScoreboardLockQuarter', params, (callback || null), (errback || null));
    }
};
Api.Submission = {
};
Api.Submission.NextQtrAccountability = {

    /*
    Get team member data for a center-reportingDate, optionally including in-progress data
    Parameters:
      center: Center
      reportingDate: date
      includeInProgress: bool
    */
    allForCenter: function(params, callback, errback) {
        return apiCall('Submission.NextQtrAccountability.allForCenter', params, (callback || null), (errback || null));
    },

    /*
    Stash data for in-progress Team Member weekly
    Parameters:
      center: Center
      reportingDate: date
      data: array
    */
    stash: function(params, callback, errback) {
        return apiCall('Submission.NextQtrAccountability.stash', params, (callback || null), (errback || null));
    }
};
Api.SubmissionCore = {

    /*
    Initialize Submission, checking date extents and center and providing some useful starting data
    Parameters:
      center: Center
      reportingDate: date
    */
    initSubmission: function(params, callback, errback) {
        return apiCall('SubmissionCore.initSubmission', params, (callback || null), (errback || null));
    },

    /*
    Finalizes Submission. Validates and creates new db objects for report details
    Parameters:
      center: Center
      reportingDate: date
      data: array
    */
    completeSubmission: function(params, callback, errback) {
        return apiCall('SubmissionCore.completeSubmission', params, (callback || null), (errback || null));
    }
};
Api.SubmissionData = {

    /*
    Ignore Me. Maybe I&#039;ll have public methods in the future.
    Parameters:
      center: string
      timezone: string
    */
    ignoreMe: function(params, callback, errback) {
        return apiCall('SubmissionData.ignoreMe', params, (callback || null), (errback || null));
    }
};
Api.TeamMember = {

    /*
    Create new team member
    Parameters:
      data: array
    */
    create: function(params, callback, errback) {
        return apiCall('TeamMember.create', params, (callback || null), (errback || null));
    },

    /*
    Update an team member
    Parameters:
      teamMember: TeamMember
      data: array
    */
    update: function(params, callback, errback) {
        return apiCall('TeamMember.update', params, (callback || null), (errback || null));
    },

    /*
    Set the weekly data for an team member
    Parameters:
      teamMember: TeamMember
      reportingDate: date
      data: array
    */
    setWeekData: function(params, callback, errback) {
        return apiCall('TeamMember.setWeekData', params, (callback || null), (errback || null));
    },

    /*
    Get team member data for a center-reportingDate, optionally including in-progress data
    Parameters:
      center: Center
      reportingDate: date
      includeInProgress: bool
    */
    allForCenter: function(params, callback, errback) {
        return apiCall('TeamMember.allForCenter', params, (callback || null), (errback || null));
    },

    /*
    Stash data for in-progress Team Member weekly
    Parameters:
      center: Center
      reportingDate: date
      data: array
    */
    stash: function(params, callback, errback) {
        return apiCall('TeamMember.stash', params, (callback || null), (errback || null));
    },

    /*
    Bulk update weekly reporting info (GITW/TDO)
    Parameters:
      center: Center
      reportingDate: date
      updates: array
    */
    bulkStashWeeklyReporting: function(params, callback, errback) {
        return apiCall('TeamMember.bulkStashWeeklyReporting', params, (callback || null), (errback || null));
    }
};
Api.UserProfile = {

    /*
    Set locale information
    Parameters:
      locale: string
      timezone: string
    */
    setLocale: function(params, callback, errback) {
        return apiCall('UserProfile.setLocale', params, (callback || null), (errback || null));
    }
};
Api.ValidationData = {

    /*
    Validate report data and return results
    Parameters:
      center: Center
      reportingDate: date
    */
    validate: function(params, callback, errback) {
        return apiCall('ValidationData.validate', params, (callback || null), (errback || null));
    }
};
