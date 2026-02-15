module.exports = (sessionId, port, secret, webhook = null) => {

	const { create, decryptMedia } = require('@open-wa/wa-automate')
	const axios = require('axios')
	const express = require('express')

	create({
		sessionId: sessionId,
		blockCrashLogs: true,
		disableSpins: true,
		headless: true,
		hostNotificationLang: 'nl_NL',
		logConsole: false,
		multiDevice: true,
		popup: true,
		useChrome: true,
	}).then((client) => {

		webhook && client.onMessage(async (data) => {
			const msgClean = async (msg) => ({
				id: msg.id,
				chat: msg.chatId,
				chatName: msg.chat?.name || null,
				from: msg.from,
				fromName: msg.sender?.pushname || null,
				to: msg.to,
				timestamp: msg.timestamp || msg.t,
				type: msg.type,
				media: msg.isMedia ? {mime: msg.mimetype || null, content: (msg.content || await decryptMedia(msg)).toString('base64')} : (msg.type === 'location' && msg.lat && msg.lng ? `${msg.lat},${msg.lng}` : null),
				text: msg.text || msg.caption || null,
				isForwarded: !!msg.isForwarded,
				isViewOnce: !!msg.isViewOnce,
			})
			const msg = await msgClean(data)
			msg.quotedMsg = data.quotedMsg ? await msgClean(data.quotedMsg) : null
			const logMsg = structuredClone(msg)
			const shortenMediaContent = (content) => `${content.substr(0, 9)}.. - ${content.length}b`
			if (logMsg.media?.content) logMsg.media.content = shortenMediaContent(logMsg.media.content)
			if (logMsg.quotedMsg?.media?.content) logMsg.quotedMsg.media.content = shortenMediaContent(logMsg.quotedMsg.media.content)
			console.log('')
			console.log(logMsg)
			await axios.post(webhook, msg)
		})

		const app = express()
		app.use((req, res, next) => req.headers['secret'] === secret ? next() : res.status(401).json({error: 'Unauthorized'}))
		app.use(express.json({limit: '96mb'}))

		app.post('/read', async (req, res) => {
			const { chat } = req.body
			await client.sendSeen(chat)
			console.log(`\nread: ${chat}`)
			res.send('ok')
		})

		app.post('/reaction', async (req, res) => {
			const { msg, emoji } = req.body
			await client.react(msg, emoji)
			console.log(`\nreaction: ${msg} - ${emoji}`)
			res.send('ok')
		})

		app.post('/text', async (req, res) => {
			const { to, text } = req.body
			await client.sendText(to, text)
			console.log(`\ntext: ${to}\n${text}`)
			res.send('ok')
		})

		app.post('/image', async (req, res) => {
			const { to, filename, image } = req.body
			const text = req.body.text || ''
			await client.sendImage(to, image, filename, text)
			console.log(`\nimage: ${to} - ${filename} (${image.length}b)\n${text}`)
			res.send('ok')
		})

		app.post('/location', async (req, res) => {
			const { to, lat, lon, text } = req.body
			const address = req.body.address || ''
			const url = req.body.url || ''
			await client.sendLocation(to, lat, lon, text)
			console.log(`\nlocation: ${lat},${lon}\n${text}`)
			res.send('ok')
		})

		app.post('/document', async (req, res) => {
			const { to, filename, document } = req.body
			const text = req.body.text || ''
			await client.sendFile(to, document, filename, text)
			console.log(`\ndocument: ${filename} (${document.length}b)\n${text}`)
			res.send('ok')
		})

		app.post('/audio', async (req, res) => {
			const { to, audio } = req.body
			await client.sendAudio(to, audio)
			console.log(`\naudio: ${to} ${audio.length}b`)
			res.send('ok')
		})

		app.post('/voice', async (req, res) => {
			const { to, audio } = req.body
			await client.sendPtt(to, audio)
			console.log(`\nvoice: ${to} ${audio.length}b`)
			res.send('ok')
		})

		app.post('/poll', async (req, res) => {
			const { to, name, options } = req.body
			const multi = !!+req.body.multi
			await client.sendPoll(to, name, options, null, multi)
			console.log(`\npoll: ${to} ${multi}\n${name}`)
			console.log(options)
			res.send('ok')
		})

		app.post('/typing/start', async (req, res) => {
			const { to } = req.body
			await client.simulateTyping(to, true)
			console.log(`\ntyping/start: ${to}`)
			res.send('ok')
		})

		app.post('/typing/stop', async (req, res) => {
			const { to } = req.body
			await client.simulateTyping(to, false)
			console.log(`\ntyping/stop: ${to}`)
			res.send('ok')
		})

    app.listen(port, '127.0.0.1', () => console.log(`\nServer "${sessionId}" running on port ${port}`))

	})
}
